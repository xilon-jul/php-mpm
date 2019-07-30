<?php

namespace Loop\Core;

use Loop\Core\Action\LoopAction;
use Loop\Core\Event\EventInfo;
use Loop\Core\Event\IPCSocketRoute;
use Loop\Core\Event\SocketPairEventInfo;
use Loop\Core\Listener\LoopEventListener;
use Loop\Protocol\Factory\ProtocolMessageFactory;
use Loop\Protocol\ProcessResolutionProtocolMessage;
use Loop\Protocol\ProtocolBuilder;
use Loop\Protocol\ProtocolMessage;
use Loop\Util\Logger;

pcntl_async_signals(true);

class Loop
{
    /**
     * @var bool $running
     */
    private $running = true;
    private $shutdown = false;

    /**
     * @var ProcessInfo $thisProcessInfo
     */
    private $thisProcessInfo;

    /**
     * @var $protocolBuilder ProtocolBuilder
     */
    private $protocolBuilder;

    private $readBuffers = [];

    /**
     * @var array
     */
    private $writeBuffers = [];

    /**
     * @var $loopActions LoopAction[]
     */
    private $loopActions = [];

    /**
     * @var $triggers string[]
     */
    private $triggers = [];

    private $events = [];

    /**
     * @var \EventBase $base
     */
    private $eb;

    private $exitCode = 0;

    // START INOTIFY ____________________________________________________
    private $inotifyInit = null;
    private $inotifyEvent;
    private $inotifyWatches = [];
    private $inotifyCallback = null;
    // END INOTIFY ______________________________________________________

    // START EIO ____________________________________________________
    private $eioEvent;
    // END EIO ______________________________________________________



    /**
     * @var int default timeout to quit loop so that it forces action to be dispatched
     */
    private static $DEFAULT_TIMEOUT = 3;

    /**
     * Loop constructor.
     * Constructs the main loop object and assign to it one or many labels. Labels are just aliases
     * to process pid which can further be used to target a process when sending a message
     * @param string ...$labels the labels to add to loop
     */
    public function __construct(string ...$labels)
    {
        $this->eb = new \EventBase();
        // Prepare messaging
        $this->_initProtocolBuilder();
        ProtocolMessageFactory::getInstance()->registerProtocol(new ProcessResolutionProtocolMessage());
        $this->thisProcessInfo = (new ProcessInfo(posix_getpid()))
            ->setIsRootOfHierarchy(true)
            ->setParentProcessInfo(new ProcessInfo(posix_getppid()))
            ->setLabels(...$labels);
        $this->_registerSighandlers();
        $this->loopActions = [];

        $this->addAction(new LoopAction(
            LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED,
            true,
            true,
            function(Loop $loop, ProcessInfo $processInfo){
                Logger::log('dispatch', "Default action for child process %-5d terminated", $processInfo->getPid());
                $loop->getProcessInfo()->removeChild($processInfo->getPid());
            }
        ));

        $this->addAction(new LoopAction(
            LoopAction::LOOP_ACTION_PROCESS_TERMINATED,
            true,
            true,
            function(Loop $loop, ProcessInfo $processInfo){
                Logger::log('dispatch', "Default action for process_terminated");
                $loop->stop();
            }
        ));

        $this->addAction(new LoopAction(
            LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED,
            true,
            true,
            function(Loop $loop, $pid){
                Logger::log('dispatch', 'Default action process channel closed for pid %-5d', $pid);
                $loop->getProcessInfo()->freePipe($pid);
            }
        ));

    }

    private function _registerSighandlers(): void
    {
        Logger::log('signal','Registering signals');
        pcntl_signal(SIGCHLD, [$this, 'sighdl']);
        pcntl_signal(SIGCONT, [$this, 'sighdl']);
        pcntl_signal(SIGTERM, [$this, 'sighdl']);
        //pcntl_signal(SIGSEGV, [$this, 'sighdl']);
    }

    private function _initProtocolBuilder(): void
    {
        $this->protocolBuilder = new ProtocolBuilder();
        $this->protocolBuilder->setReadCb(ProcessResolutionProtocolMessage::class, function (ProcessResolutionProtocolMessage $message) {
            $this->_ipcMessageHandler($message);
        });
    }

    /**
     * Signal handler.
     * Defaults handler is used for :
     *  - SIGTERM => Gracefully shutdowns the processes
     *  - SIGCHLD, SIGSTOP, SIGCONT for internal usage
     * @param int $signo the signal number received (@see man kill)
     */
    public function sighdl(int $signo)
    {
        $status = null;
        /**
         * @var array $rusage
         * @see http://manpagesfr.free.fr/man/man2/getrusage.2.html
         */
        $rusage = [];
        Logger::log('signal',"Handling signal %d", $signo);
        switch ($signo) {
            case SIGSEGV:
                Logger::log('signal','OHHHHH NOOOOOOO !!!! SEGFAULT in %d', posix_getpid());
                exit(255);
            case SIGTERM:
                Logger::log('signal','SIGTERM caught in %-5d', posix_getpid());
                $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, $this->thisProcessInfo);
                $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, true);
                $this->thisProcessInfo->setStatus(ProcessInfo::PROCESS_ST_EXITED, sprintf("Process exited with signal: %d", SIGTERM))
                    ->setResourceUsage($rusage);
                break;
            case SIGSTOP:
            case SIGCONT:
            case SIGCHLD:
                $i = 0;
                while (($pid = pcntl_waitpid(-1, $status, WNOHANG | WCONTINUED | WUNTRACED, $rusage)) > 0) {
                    ++$i;
                    $this->_handleWaitPid($pid, $status, $rusage);
                }
                if($i === 0){
                    // We were called for a signal but we could not wait for the child
                    $err = pcntl_get_last_error();
                    switch($err){
                        case PCNTL_ECHILD:
                            Logger::log('signal',"Cannot retrieve child process status: no child to reap status from");
                            break;
                        default:
                            Logger::log('signal',"Unhandled pcntl error %d", $err);
                    }
                }
                break;
        }
    }

    private function _handleWaitPid(int $pid, int $status, array $rusage): void {
        $processInfo = $this->thisProcessInfo->getProcessInfo($pid);
        if(!$processInfo){
            // A loop might fork processes using proc_open or any other functions, therefor we might
            // not have a corresponding child for the process we received the SIGCHLD (eg: a see proc_open)
            $this->thisProcessInfo->addChild(new ProcessInfo($pid));
            $processInfo = $this->thisProcessInfo->getProcessInfo($pid);
        }
        Logger::log('signal',"Waited after process child %d - All children = (%s)", $pid, implode(',', array_map(function(ProcessInfo $c){
            return $c->getPid();
        }, $this->thisProcessInfo->getChildren())));
        if (pcntl_wifexited($status)) {
            Logger::log('signal',"Pid %-5d has exited", $pid);
            $processInfo->setStatus(ProcessInfo::PROCESS_ST_EXITED, sprintf("Process exited with status: %d", $status))
                ->setResourceUsage($rusage);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, true);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, $processInfo);
        } elseif (pcntl_wifstopped($status)) {
            $processInfo->setStatus(ProcessInfo::PROCESS_ST_STOPPED)
                ->setResourceUsage($rusage);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_STOPPED, true);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_STOPPED, $processInfo);
        } elseif (pcntl_wifcontinued($status)) {
            $processInfo->setStatus(ProcessInfo::PROCESS_ST_RUNNING, sprintf("Process has been rescheduled with SIGCONT"))
                ->setResourceUsage($rusage);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_CONTINUED, true);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_CONTINUED, $processInfo);
        } elseif (pcntl_wifsignaled($status)) {
            Logger::log('signal', "Pid %-5d exited due to uncaught signal", $pid);
            $processInfo->setStatus(ProcessInfo::PROCESS_ST_EXITED, sprintf("Process exited due to uncaught signal: %d", $status))
                ->setResourceUsage($rusage);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, $processInfo);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, true);
        }
    }


    public function removeFileWatch(string $pathname){
        $fd = null;
        $this->inotifyWatches = array_filter($this->inotifyWatches[posix_getpid()], function(array $watch) use ($pathname, &$fd) {
           if($watch['pathname'] === $pathname){
               $fd = $watch['fd'];
               return false;
           }
           return true;
        });
        if($fd !== null){
            Logger::log('inotify','Remove inotify watch with fd %s', $fd);
            inotify_rm_watch($this->inotifyInit, $fd);
        }
    }

    /**
     * Adds a file to be watched for any event (see inotify)
     * @param string $pathname the file or directory to be watched
     * @param \Closure $callback the callback to invoke when events are received, you should give the same callback as it will be registered once
     * The callback receives as first argument the loop instance, and as second argument an inotify array as returned by inotify_read with an extra key pathname
     * corresponding to the file being watched
     * @return Loop this loop instance
     */
    public function addFileWatch(string $pathname, \Closure $callback): Loop
    {
        if(!file_exists($pathname)){
            return $this;
        }
        if(!$this->inotifyCallback){
            $this->inotifyCallback = $callback;
        }
        Logger::log('inotify', 'Add file watch %s', $pathname);
        if ($this->inotifyInit === null) {
            $this->inotifyInit = inotify_init();
            $this->bindInotifyToLibevent();
        }
        $this->inotifyWatches[posix_getpid()][] = ['pathname' => $pathname, 'fd' => inotify_add_watch($this->inotifyInit, $pathname, IN_ALL_EVENTS)];
        Logger::log('inotify', 'Watch with fd %d added', end($this->inotifyWatches)['fd']);
        return $this;
    }

    private function bindInotifyToLibevent(){
        $this->registerActionForTrigger(LoopAction::LOOP_ACTION_INOTIFY_EVENT, true, false, function(Loop $loop, ...$args) {
            call_user_func($this->inotifyCallback, $loop, ...$args);
        }, function(){ return true; });

        if($this->inotifyEvent !== null){
            $this->inotifyEvent->del();
            $this->inotifyEvent->free();
            $this->inotifyEvent = null;
        }
        $this->inotifyEvent = new \Event($this->eb, $this->inotifyInit, \Event::READ | \Event::PERSIST, function ($fd, int $what, $args) {
            Logger::log('inotify', 'Inotify libevent event callback invoked...');
            $inotifyData = inotify_read($fd);
            $inotifyData = array_map(function($eventStructure){
                // Get pathname from added watches
                $watchFd = $eventStructure['wd'];
                list($watchDescription) = array_values(array_filter($this->inotifyWatches[posix_getpid()], function($value) use($watchFd) {
                    return $watchFd === $value['fd'];
                }));
                $eventStructure['pathname'] = $watchDescription['pathname'];
                return $eventStructure;
            }, $inotifyData);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_INOTIFY_EVENT, $inotifyData);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_INOTIFY_EVENT, true);
        });
        $this->inotifyEvent->add();
    }

    public function registerActionForTrigger(string $triggerName, bool $persistent, bool $immediate, $callable, $forkSurviveStrategy = null): LoopAction {
        $action = new LoopAction($triggerName, $persistent, $immediate, $callable, $forkSurviveStrategy);
        $this->addAction($action);
        return $action;
    }

    public function removeAction(LoopAction $action){
        $this->loopActions = array_filter($this->loopActions, function(LoopAction $a) use($action) {
            return $a !== $action;
        });
        $action = null;
    }

    public function addPeriodTimer($interval, callable $callable, $maxExecution = -1, $startNow = false): Loop
    {
        $flags = $startNow ? \Event::TIMEOUT : \Event::TIMEOUT | \Event::PERSIST;
        $ev = new \Event($this->eb, -1, $flags, function () use ($maxExecution, $startNow, $interval, $callable, &$ev) {
            Logger::log('timer', 'Executing timer');
            static $nbExecution = 0;
            if ($nbExecution === $maxExecution) {
                $ev->del();
                $ev->free();
                $this->events = array_values(array_filter($this->events, function($event) use($ev) {
                    return $ev === $event;
                }));
                $ev = null;
                return;
            }
            if($startNow){
                $ev->add($interval);
            }
            $nbExecution++;
            call_user_func($callable, $this);
        });
        if($startNow){
            $ev->add(0);
        }
        else {
            $ev->add($interval);
        }
        $this->events[] = $ev;
        return $this;
    }

    private function freeEvents(){
        $nbEvents = count($this->events);
        for($i = 0; $i < $nbEvents; $i++){
            $evt = $this->events[$i];
            $evt->del();
            $evt->free();
            $evt = null;
        }
        $this->events = [];
    }

    public function setCliCommandTitle(string $title) : Loop {
        cli_set_process_title($title);
        return $this;
    }

    public function setDefaultTimeout(float $timeout){
        self::$DEFAULT_TIMEOUT = $timeout;
    }


    /**
     * This method is for IPC accross the process tree. You can send message
     * to one ore more processes. You must install an handler / action for the trigger name
     * (LoopAction::LOOP_ACTION_MESSAGE_RECEIVED) to handle the message
     * @see LoopAction::LOOP_ACTION_MESSAGE_RECEIVED
     * @param ProcessResolutionProtocolMessage $message
     * @return Loop
     * @throws \Loop\Protocol\Exception\ProtocolException
     */
    public function submit(ProcessResolutionProtocolMessage $message): Loop
    {
        if (!$message->getField('destination_pid')->getValue() && !$message->getField('destination_label')->getValue()) {
            throw new \Exception("Message is missing a destination field _pid or _label");
        }
        $targetPID = $message->getField('destination_pid')->getValue();
        $targetLabel = $message->getField('destination_label')->getValue();


        $targets = array_filter($this->thisProcessInfo->getPipes(), function ($pipe) use ($message, $targetPID, $targetLabel) {
            $cond = $pipe->getPid() === $targetPID || in_array($targetLabel, $pipe->getLabels(), true) !== false;
            return $cond;
        });

        $message->getField('sent_at')->setValue(time());
        // Message is not a direct message to a parent or child process
        if (count($targets) === 0) {
            $targets = $this->thisProcessInfo->getPipes();
        }
        foreach ($targets as $pipe) {
            $routedMessage = clone $message;
            $routedMessage->getField('previous_pid')->setValue(posix_getpid());
            $routedMessage->getField('source_pid')->setValue(posix_getpid());
            $fdNumber = (int) sprintf('%d', $pipe->getFd());
            $this->writeBuffers[$fdNumber][] = $this->protocolBuilder->toByteStream($routedMessage);
            ($pipe->getEwrite())->add();
        }
        return $this;
    }

    /**
     * Parses action message and coalesce any message that needs to be
     * A message is said to be coaslcent if it has its coalesce field set to true.
     * When so, only the last message if they have the same signature will be triggered.
     */
    private function _coalesceMessage(): void {
        list($messageReceivedAction) = array_filter($this->loopActions, function(LoopAction $action) use(&$actionIndex) {
           return $action->trigger() === LoopAction::LOOP_ACTION_MESSAGE_RECEIVED;
        });

        if(!$messageReceivedAction){
            return;
        }
        // Parse runtime args starting at index 1, check protocol messag
        // that has coalesce option set to true then hash the data payload
        // and keep the last data payload if same hash occurs more than once
        $args = $messageReceivedAction->getRuntimeArgs();
        $newRuntimeArgs = [$args[0]];
        $nbArgs = count($args);
        $hashmap = [];
        Logger::log('dispatch', "Message before coalesce action message received %d", $nbArgs);
        for($i = 1; $i < $nbArgs; $i++){
            /**
             * @var $argMessage ProcessResolutionProtocolMessage
             */
            $argMessage = $args[$i];
            if($argMessage->getField('coalesce')->getValue() !== 1){
                continue;
            }
            $hash = md5($argMessage->getField('data')->getValue());
            $hashmap[$hash] = $argMessage;
            Logger::log('dispatch',"Coalesce message with hash %s", $hash);
        }

        array_walk(array_values($hashmap), function($message) use(&$newRuntimeArgs) {
            $newRuntimeArgs[] = $message;
        });
        Logger::log('dispatch', "Message after coalesce action message received %d", count($newRuntimeArgs));
        $messageReceivedAction->removeRuntimeArgs();
        $messageReceivedAction->setRuntimeArgs(...$newRuntimeArgs);
    }

    /**
     * Callback invoked when the protocol has succeeded in decoding a message
     * @param ProcessResolutionProtocolMessage $message
     * @throws \Loop\Protocol\Exception\ProtocolException
     */
    private function _ipcMessageHandler(ProcessResolutionProtocolMessage $message)
    {
        // Check that the message is targeted to this process based on the destination name, pid or broadcast
        $broadcast = $message->getField('broadcast')->getValue() === 1;

        $destinationLabel = $message->getField('destination_label')->getValue();
        $destinationPID = $message->getField('destination_pid')->getValue();

        if ($broadcast ||
            ($hasLabel = $this->thisProcessInfo->hasLabel($destinationLabel)) ||
            $this->thisProcessInfo->getPid() === $destinationPID
        ) {
            Logger::log('socket',"Received message from pid %-5d with data : %s\n", $message->getField('source_pid')->getValue(), $message->getField('data')->getValue());
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, $message);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true);
            if (!$broadcast && !$hasLabel) {
                // Its a direct message, return
                return;
            }
        }
        // Message is a broadcast or targeted to another process, send it to any route except the route where it comes from
        Logger::log('socket',"Message needs to be routed elsewhere %s / %s", $destinationPID, $destinationLabel);
        foreach ($this->thisProcessInfo->getPipes() as $pipe) {
            if ($pipe->getPid() === $message->getField('previous_pid')->getValue()) {
                continue;
            }

            $routedMessage = clone $message;
            $routedMessage->getField('previous_pid')->setValue(posix_getpid());
            $fdNumber = (int) sprintf('%d', $pipe->getFd());
            $this->writeBuffers[$fdNumber][] = $this->protocolBuilder->toByteStream($routedMessage);
            ($pipe->getEwrite())->add();
        };
    }

    private function _readSocket($fd, int $what, \Event $ev, $args)
    {
        if($what & \Event::TIMEOUT){
            // Logger::log('_read timeout');
            return;
        }
        $intFd = (int) sprintf('%d', $fd);
        Logger::log('socket','_read from fd %d with event flags %d', $fd, $what);
        $this->readBuffers[$intFd] = strlen($this->readBuffers[$intFd]) > 0 ? $this->readBuffers[$intFd] : '';
        while (true) {
            $buffer = '';
            if (false === ($bytes = @socket_recv($fd, $buffer, 8092, 0))) {
                $errnum = socket_last_error($fd);
                $this->readBuffers[$intFd] .= $buffer;
                Logger::log('socket',"Socket error: %d - %s", $errnum, socket_strerror($errnum));
                switch ($errnum) {
                    case 0:
                        Logger::log('socket',"Socket recv has failed but error code is success");
                        break 2;
                    case SOCKET_EAGAIN:
                        Logger::log('socket',"Socket egain");
                        break 2;
                    case SOCKET_ECONNRESET:
                        break;
                    default:
                        throw new \RuntimeException("Unhandled socket error");
                }
            }
            $this->readBuffers[$intFd] .= $buffer;
            // Connection closed
            if ($bytes === 0) {
                Logger::log('socket', "No bytes....");
                $pid = $this->thisProcessInfo->getPidBoundToFd($fd);
                if($pid === null){
                    // No pid, other end was probably already removed
                    $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, true);
                    $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, $this->thisProcessInfo);
                    return;
                }
                Logger::log('socket', "Connection closed with fd %-5d bound to pid %-5d", $intFd, $pid);
                // Case whenever the pipes that gets closed is the only one
                if($this->thisProcessInfo->countPipes() === 1 && $this->thisProcessInfo->hasPipe($pid)) {
                    Logger::log('socket', "Last pipe broken");
                    $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, true);
                    $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, $this->thisProcessInfo);
                }
                $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED, true);
                $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED, $pid);

                if (!$this->thisProcessInfo->isRootOfHierarchy() && ($ppid = posix_getppid()) <= 1) {
                    Logger::log('socket',"Became orphan: parent process = %-5d", $ppid);
                    $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_ORPHANED, true);
                    $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_ORPHANED);
                    $this->stop();
                }
                return;
            }
            Logger::log('socket', "Read %d bytes from fd %d", strlen($buffer), $fd);
        }
        try {
            Logger::log('socket', 'Try read from protocol message with buffer: %s', $this->readBuffers[$intFd]);
            while(true){
                $this->protocolBuilder->read($this->readBuffers[$intFd]);
                Logger::log('socket', 'Message read');
            }
        } catch (\Exception $e) {
            // Do nothing wait until more bytes
            Logger::log('socket', "Protocol exception %s", $e->getMessage());
        }
    }

    public function __clone()
    {
        // TODO: Implement __clone() method.
    }

    private function _write($fd, int $what, \Event $ev, $args)
    {

        // Fd is ready for writing, write as max bytes as we can, persist event until we have write for this fd
        $intFd = (int) sprintf('%d', $fd);
        Logger::log('socket', "_write to fd %d", $intFd);
        while (true) {
            if (($nbMessages = count($this->writeBuffers[$intFd])) === 0) {
                Logger::log('socket', 'No message in stack for fd %d', $intFd);
                break;
            }
            $index = 0;
            if (strlen($this->writeBuffers[$intFd][$index]) === 0) {
                Logger::log('socket', "No data to write for first element in fd %d stack", $intFd);
                break;
            }
            // fprintf(STDOUT, 'In process %d _write to fd %d%s', posix_getpid(), $fd, PHP_EOL);
            if (false === ($writtenBytes = @socket_write($fd, $this->writeBuffers[$intFd][$index], 8092))) {
                $error = socket_last_error($fd);
                Logger::log('socket', '_write has failed with error code: %d / %s', $error , socket_strerror($error));
                break;
            }
            Logger::log('socket', 'Wrote %d bytes', $writtenBytes);
            if ($writtenBytes === 0) {
                break;
            }
            $remainingBytes = substr($this->writeBuffers[$intFd][$index], $writtenBytes);
            if ($remainingBytes) {
                $this->writeBuffers[$intFd][$index] = $remainingBytes;
            } else {
                array_shift($this->writeBuffers[$intFd]);
            }
        }
        // Check if we have more messages to write
        if (count($this->writeBuffers[$intFd]) === 0 || $this->writeBuffers[$intFd][0] === '') {
            Logger::log('socket', "Removing write event");
            $ev->del();
        }
    }


    /**
     * Parametrized each action by their name, which means that all occurences of a trigger would be prepared by appending parameters
     * with the arguments given
     * @param string $triggerName
     * @param mixed ...$args
     */
    private function prepareActionForRuntime(string $triggerName, ...$args){
        /**
         * @var $action LoopAction
         */
        array_walk($this->loopActions, function(LoopAction $action) use($triggerName, $args) {
           if($action->trigger() !== $triggerName) return;
           $action->setRuntimeArgs(...$args);
           Logger::log('dispatch', "Settings args %s / %s for trigger %s ", implode(',', $args), implode(',', $action->getRuntimeArgs()), $triggerName);
           if($action->isImmediate()){
               $this->eb->stop();
           }
        });
    }

    /**
     * Is the main loop running
     * @return bool true if so
     */
    public function isRunning(): bool {
        return $this->running;
    }

    /**
     * Forks a new child process
     * @param \Closure|null $childCallback a callable invoked in the child process context
     * @param string ...$labels the process labels to assign
     * @return int the process pid in the parent context the child never returns
     * @throws \Exception if for any reason we cant fork
     */
    public function fork(\Closure $childCallback = null, string ...$labels): int
    {
        if(!$this->isRunning()){
            return -1;
        }
        // Notifies events that needs to that we are within a fork context (just before)
        $pairs = [];
        if (false === socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pairs)) {
            throw new \Exception("Cannot create socket pair");
        }
        // 0 => Parent reads from child and writes to child
        // 1 => Childs reads from parent and writes to parent
        socket_set_nonblock($pairs[0]);
        socket_set_nonblock($pairs[1]);
        $pid = pcntl_fork();
        if ($pid === -1) {
            return -1;
        } else if ($pid > 0) {
            // Parent
            Logger::log('fork', "Fork process child: %-5d", $pid);
            socket_close($pairs[1]);
            $read = null;
            $read = new \Event($this->eb, $pairs[0], \Event::READ | \Event::PERSIST, function ($fd, int $what, $args) use (&$read) {
                $this->_readSocket($fd, $what, $read, $args);
            });
            $write = new \Event($this->eb, $pairs[0], \Event::WRITE, function ($fd, int $what, $args) use (&$write) {
                $this->_write($fd, $what, $write, $args);
            }, $pid);
            // This means to read from pid $pid use $read event, to write to pid use $write
            $this->thisProcessInfo->addChild(
                (new ProcessInfo($pid))
                    ->setLabels(...$labels)
            )->addPipe(new Pipe($pid, $pairs[0], $read, $write, ...$labels));
            $read->add(self::$DEFAULT_TIMEOUT);
            array_push($this->events, $read, $write);
            Logger::log('fork', "Ended fork: %-5d", $pid);
            return $pid;
        } else {
            $this->eb->reInit();
            pcntl_sigprocmask(SIG_BLOCK, []);
            socket_close($pairs[0]);
            $this->freeEvents();
            if($this->inotifyEvent){
                $this->inotifyEvent->free();
            }
            Logger::log('fork', 'Fork in child context');



            $this->inotifyInit = null;
            // Reset exitCode
            $this->exitCode = 0;
            // Clear all buffers
            $this->writeBuffers = $this->readBuffers = [];
            // Keep all actions that wish to propagate and remove all triggers
            // Note that when process is being forked it can receive a signal before this line thus we would lose the action handling as the trigger array is reset
            $this->triggers = array_filter($this->triggers, function($value, $key){
                return $key === posix_getpid();
            }, ARRAY_FILTER_USE_BOTH);
            $this->loopActions = array_filter($this->loopActions, function(LoopAction $action){
                return $action->survivesAcrossFork($this);
            });
            $pInfo = new ProcessInfo(posix_getpid());
            // It it safe to free any inherited pipes from parent memory as this new child cant have a single child yet
            $pInfo->setLabels(...$labels)
                ->setParentProcessInfo(clone $this->thisProcessInfo);
            $this->_initProtocolBuilder();
            // Logger::log("Socket to parent : %d", \EventUtil::getSocketFd($pairs[1]));
            $read = new \Event($this->eb, $pairs[1], \Event::READ | \Event::PERSIST, function ($fd, int $what, $args) use (&$read) {
                $this->_readSocket($fd, $what, $read, $args);
            });
            $read->add(self::$DEFAULT_TIMEOUT);
            $write = new \Event($this->eb, $pairs[1], \Event::WRITE, function ($fd, int $what, $args) use (&$write) {
                $this->_write($fd, $what, $write, $args);
            });
            array_push($this->events, $read, $write);
            $pInfo->addPipe(
                new Pipe(posix_getppid(), $pairs[1], $read, $write, ...$pInfo->getParentProcessInfo()->getLabels())
            );
            $this->thisProcessInfo = $pInfo;
            if (is_callable($childCallback)) {
                call_user_func($childCallback, $this);
            }
            self::loop();
        }
        return posix_getpid();
    }


    /**
     * Register one or more actions. Registering an action does not mean it will
     * get triggered. The triggering of an action is based on the trigger flag
     * @param LoopAction ...$action actions to register
     */
    private function addAction(LoopAction ...$action): void
    {
        if (count($action) === 0) {
            return;
        }
        array_push($this->loopActions, ...$action);
    }

    /**
     * Mark an action to be triggered or not on next dispatch loop
     * @param string $triggerName the action trigger name
     * @param bool $shouldTrigger whether the action should be triggered on next event loop run
     */
    public function setTriggerFlag(string $triggerName, bool $shouldTrigger){
        $this->triggers[posix_getpid()][$triggerName] = $shouldTrigger;
    }


    /**
     * Dispatch actions is executed during the event loop.
     * It taverses all registered actions and triggers them. You can register actions
     * using @see addAction(LoopAction ...$action)
     */
    private function dispatchActions(): void
    {
        $this->_coalesceMessage();
        /**
         * @var $action LoopAction
         */
        $triggersToDisable = [];
        usort($this->loopActions, function (LoopAction $a, LoopAction $b) {
            if ($a->getPriority() === $b->getPriority()) return 0;
            return ($a->getPriority() < $b->getPriority() ? -1 : 1);
        });
        $cpid = posix_getpid();
        $nbActions = count($this->loopActions);
        if($nbActions !== 0){
            $this->thisProcessInfo->setAvailable(false);
        }
        Logger::log('dispatch', 'Loop through actions');
        for($i = 0; $i < $nbActions; $i++){
            $action = $this->loopActions[$i];
            $trigger = $action->trigger();
            if(!array_key_exists($cpid, $this->triggers) || !array_key_exists($trigger, $this->triggers[$cpid]) || $this->triggers[posix_getpid()][$trigger] === false){
                // Do nothing and keep action in stack
                continue;
            }
            Logger::log('dispatch', 'Dispatch action %s with priority %d', $trigger, $action->getPriority());
            $action->invoke($this, ...$action->getRuntimeArgs());
            $triggersToDisable[$trigger] = false;
            if(!$action->isPersistent()){
                unset($this->loopActions[$i]);
            }
        }
        $this->loopActions = array_values($this->loopActions);

        array_walk($this->loopActions, function(LoopAction $action){
           $action->removeRuntimeArgs();
        });

        array_walk($triggersToDisable, function($triggerFlag, $triggerName){
            $this->setTriggerFlag($triggerName, $triggerFlag);
        });
        $this->thisProcessInfo->setAvailable(true);
    }


    /**
     * Gets this process info
     * @return ProcessInfo the process readable information
     */
    public function getProcessInfo(): ProcessInfo
    {
        return $this->thisProcessInfo;
    }

    /**
     * Closes stdin, stdout and stderr file descriptor.
     * Note that if loggin is enabled this functions will not close any fd
     * @return Loop
     */
    public function closeStandardFileDescriptors(): Loop {
        if($this->enableLogger){
            Logger::log('daemon', 'Warning, cannot close standard fds if logging is enabled');
            return $this;
        }
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        return $this;
    }

    /**
     * Changes this process working directory to $directory
     * @param string $directory the directory
     * @return Loop this loop instance
     */
    public function setWorkingDirectory(string $directory): Loop {
        if(!is_dir($directory)){
            throw new \RuntimeException("No such directory $directory");
        }
        if( false === chdir($directory)){
            throw new \RuntimeException("Cannot change working directory");
        }
        return $this;
    }

    /**
     * Detach this process from its controlling terminal and make it a session leader.
     */
    public function detach(): Loop
    {
        $pid = pcntl_fork();
        if($pid < 0){
            throw new \RuntimeException("Cannot fork");
        }
        if($pid > 0){
            // Parent
            exit(0);
        }
        if(-1 === posix_setsid()){
            throw new \RuntimeException("Cannot be a session leader");
        }
        return $this;
    }

    /**
     * Sets this process exit code
     * @param int $exitCode
     * @return Loop
     */
    public function setExitCode(int $exitCode = 0): Loop {
        if($exitCode < 0 || $exitCode >= 255){
            throw new \RuntimeException("Exit code must be between 0 and 254. 255 is reserved for PHP");
        }
        $this->exitCode = $exitCode;
        return $this;
    }

    /**
     * Shuts down the executor and all of its children by sending a SIGTERM signal.
     * Note that children will continue processing loop actions before shutting down.
     */
    public function shutdown(): void {
        Logger::log('daemon', 'Asking processes to shutdown');
        if(!$this->running){
                Logger::log('daemon', 'Process was already ask to shutdown...');
                return;
        }
        $this->stop();
    }

    public function signal(int $signal): Loop {
        array_walk($this->getProcessInfo()->getChildren(), function(ProcessInfo $childInfo) use($signal) {
            Logger::log('signal', "Sending signal %d to %-5d", $signal, $childInfo->getPid());
            posix_kill($childInfo->getPid(), $signal);
        });
        return $this;
    }


    /**
     * Ask this loop to stop and free all resources.
     * Timers are freed as well as any pair of sockets used for internal process communication.
     * Pending actions are dispatched before the process exits.
     * @return Loop this loop
     */
    public function stop(): Loop
    {
        if(!$this->isRunning()){
            return $this;
        }
        Logger::log('daemon', "Stopping loop");
        $this->eb->exit();
        $this->running = false;
        return $this;
    }



    /**
     * Starts the daemon loop
     */
    public function loop(): void
    {
        $this->running = true;
        if($this->thisProcessInfo->isRootOfHierarchy()){
            Logger::log('dispatch', "Started master process");
        }
        pcntl_sigprocmask(SIG_UNBLOCK, []);
        while($this->running) {
            $this->eb->loop(\EventBase::LOOP_ONCE);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_BEFORE_DISPATCH);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_BEFORE_DISPATCH, true);
            $this->dispatchActions();
        }
        $this->signal(SIGTERM);
        Logger::log('daemon', "Main loop stopped");

        $status = null;
        $rusage = [];
        while(($pid = pcntl_wait($status, WUNTRACED)) > 0){
            Logger::log("Waited for child: %-5d", $pid);
            $this->_handleWaitPid($pid, $status, $rusage);
            $this->dispatchActions();
        }
        $this->thisProcessInfo->free();
        $this->eb->free();
        Logger::log('daemon', "All children exited, bye bye !!!!");
        exit($this->exitCode);
    }
}
