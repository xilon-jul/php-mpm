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

pcntl_async_signals(true);

class Loop
{
    /**
     * @var bool $running
     */
    private $running = true;

    private $enableLogger = true;

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

    /**
     * @var \Event[] $timers
     */
    private $timers = [];

    /**
     * @var \EventBase $base
     */
    private $eb;

    private $exitCode = 0;

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
                $this->log("Action: child process %-5d terminated", $processInfo->getPid());
                $loop->getProcessInfo()->removeChild($processInfo->getPid());
            }
        ));

        $this->addAction(new LoopAction(
            LoopAction::LOOP_ACTION_PROCESS_TERMINATED,
            true,
            true,
            function(Loop $loop, ProcessInfo $processInfo){
                $this->log("Stop loop due to trigger action_process_terminated");
                $loop->stop();
            }
        ));

        $this->addAction(new LoopAction(
            LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED,
            true,
            true,
            function(Loop $loop, $pid){
                $loop->getProcessInfo()->freePipe($pid);
            }
        ));
    }

    private function _registerSighandlers(): void
    {
        pcntl_signal(SIGCHLD, [$this, 'sighdl']);
        pcntl_signal(SIGCONT, [$this, 'sighdl']);
        pcntl_signal(SIGTERM, [$this, 'sighdl']);
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
        $this->log("Handling signal %d", $signo);
        switch ($signo) {
            case SIGTERM:
                $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, $this->thisProcessInfo);
                $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, true);
                $this->thisProcessInfo->setStatus(ProcessInfo::PROCESS_ST_EXITED, sprintf("Process exited with signal: %d", SIGTERM))
                    ->setResourceUsage($rusage);
                break;
            case SIGSTOP:
            case SIGCONT:
            case SIGCHLD:
                while (($pid = pcntl_wait($status, WNOHANG | WUNTRACED | WCONTINUED, $rusage)) > 0) {
                    $this->_handleWaitPid($pid, $status, $rusage);
                }
                break;
        }
    }

    private function _handleWaitPid(int $pid, string $status, array $rusage): void {
        $this->log("Waited after process child %d - All children = (%s)", $pid, implode(',', array_map(function(ProcessInfo $c){
            return $c->getPid();
        }, $this->thisProcessInfo->getChildren())));
        $processInfo = $this->thisProcessInfo->getProcessInfo($pid);
        if (pcntl_wifexited($status)) {
            $this->log("Pid %-5d has exited", $pid);
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
            $this->log("Pid %-5d exited due to uncaught signal", $pid);
            $processInfo->setStatus(ProcessInfo::PROCESS_ST_EXITED, sprintf("Process exited due to uncaught signal: %d", $status))
                ->setResourceUsage($rusage);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, $processInfo);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, true);
        }
    }

    public function setLoggingEnabled(bool $enabled): Loop {
        $this->enableLogger = $enabled;
        return $this;
    }

    public function registerActionForTrigger(string $triggerName, bool $persistent, bool $immediate, $callable, $forkSurviveStrategy = null): Loop {
        $this->addAction(new LoopAction($triggerName, $persistent, $immediate, $callable, $forkSurviveStrategy));
        return $this;
    }


    public function addPeriodTimer($interval, $maxExecution = -1, callable $callable): Loop
    {
        $ev = new \Event($this->eb, -1, \Event::TIMEOUT | \Event::PERSIST, function () use ($maxExecution, $callable, &$ev) {
            static $nbExecution = 0;
            if ($nbExecution === $maxExecution) {
                $ev->del();
                return;
            }
            $nbExecution++;
            call_user_func($callable);

        });
        $ev->add($interval);
        $this->timers[] = $ev;
        return $this;
    }

    private function freeTimers(){
        $nbTimers = count($this->timers);
        for($i = 0; $i < $nbTimers; $i++){
            $timer = $this->timers[$i];
            $timer->del();
            $timer->free();
            $timer = null;
        }
        $this->timers = [];
    }

    public function setCliCommandTitle(string $title) : Loop {
        cli_set_process_title($title);
        return $this;
    }

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


        // Message is not a direct message to a parent or child process
        if (count($targets) === 0) {
            $targets = $this->thisProcessInfo->getPipes();
        }
        foreach ($targets as $pipe) {
            $routedMessage = clone $message;
            $routedMessage->getField('previous_pid')->setValue(posix_getpid());
             $routedMessage->getField('source_pid')->setValue(posix_getpid());
            $this->writeBuffers[\EventUtil::getSocketFd($pipe->getFd())][] = $this->protocolBuilder->toByteStream($routedMessage);
            ($pipe->getEwrite())->add();
        }
        return $this;
    }

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
            $this->log("Received message from pid %-5d with data : %s\n", $message->getField('source_pid')->getValue(), $message->getField('data')->getValue());
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, $message);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true);
            if (!$broadcast && !$hasLabel) {
                // Its a direct message, return
                return;
            }
        }
        // Message is a broadcast or targeted to another process, send it to any route except the route where it comes from
        $this->log("Message needs to be routed elsewhere");
        foreach ($this->thisProcessInfo->getPipes() as $pipe) {
            if ($pipe->getPid() === $message->getField('previous_pid')->getValue()) {
                continue;
            }

            $routedMessage = clone $message;
            $routedMessage->getField('previous_pid')->setValue(posix_getpid());
            $this->writeBuffers[\EventUtil::getSocketFd($pipe->getFd())][] = $this->protocolBuilder->toByteStream($routedMessage);
            ($pipe->getEwrite())->add();
        };
    }

    private function _read($fd, int $what, \Event $ev, $args)
    {
        $socketFd = \EventUtil::getSocketFd($fd);
        $this->readBuffers[$socketFd] = strlen($this->readBuffers[$socketFd]) > 0 ? $this->readBuffers[$socketFd] : '';
        while (true) {
            $buffer = '';
            if (false === ($bytes = @socket_recv($fd, $buffer, 8092, 0))) {
                $errnum = socket_last_error($fd);
                $this->log("Socket error: %d - %s", $errnum, socket_strerror($errnum));
                switch ($errnum) {
                    case SOCKET_EAGAIN:
                        break 2;
                    case SOCKET_ECONNRESET:
                        break;
                    default:
                        throw new \RuntimeException("Unhandled socket error");
                }
            }
            // Connection closed
            if ($bytes === 0) {
                $this->log("Connection closed with fd %-5d bound to pid %-5d", $socketFd, $args);

                // Case whenever the pipes that gets closed is the only one
                if($this->thisProcessInfo->countPipes() === 1 && $this->thisProcessInfo->hasPipe($args)) {
                    $this->log("Last pipe broken");
                    $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, true);
                    $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, $this->thisProcessInfo);
                }
                $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED, true);
                $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED, $args);

                if (!$this->thisProcessInfo->isRootOfHierarchy() && ($ppid = posix_getppid()) <= 1) {
                    $this->log("Became orphan: parent process = %-5d", $ppid);
                    $this->setTriggerFlag(LoopAction::LOOP_ACTION_PROCESS_ORPHANED, true);
                    $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_PROCESS_ORPHANED);
                    $this->stop();
                }
                return;
            }
            $this->log("Read %d bytes from fd %d", $bytes, $fd);
            $this->readBuffers[$socketFd] .= $buffer;
        }
        try {
            $this->protocolBuilder->read($this->readBuffers[$socketFd]);
        } catch (\Exception $e) {
            // Do nothing wait until more bytes
            $this->log("Protocol exception %s", $e->getMessage());
        }
    }

    private function _write($fd, int $what, \Event $ev, $args)
    {
        // Fd is ready for writing, write as max bytes as we can, persist event until we have write for this fd
        $socketFd = \EventUtil::getSocketFd($fd);
        $this->log("_write to fd %-5d", $socketFd);
        while (true) {
            if (($nbMessages = count($this->writeBuffers[$socketFd])) === 0) {
                break;
            }
            $index = 0;
            if (strlen($this->writeBuffers[$socketFd][$index]) === 0) {
                $this->log("No data to write for first element in fd %d stack", $socketFd);
                break;
            }
            // fprintf(STDOUT, 'In process %d _write to fd %d%s', posix_getpid(), $fd, PHP_EOL);
            if (false === ($writtenBytes = @socket_write($fd, $this->writeBuffers[$socketFd][$index], 8092))) {
                break;
            }
            $this->log('Wrote %d bytes', $writtenBytes);
            if ($writtenBytes === 0) {
                break;
            }
            $remainingBytes = substr($this->writeBuffers[$socketFd][$index], $writtenBytes);
            if ($remainingBytes) {
                $this->writeBuffers[$socketFd][$index] = $remainingBytes;
            } else {
                array_shift($this->writeBuffers[$socketFd]);
            }
        }
        // Check if we have more messages to write
        if (count($this->writeBuffers[$socketFd]) === 0 || $this->writeBuffers[$socketFd][0] === '') {
            $this->log("Removing write event");
            $ev->del();
        }
    }

    private function log(string $format, ...$args){
        if(!$this->enableLogger){
            return;
        }
        $log = sprintf($format, ...$args);
        fprintf(STDOUT, "Pid: %5d - %-100s\n", posix_getpid(), $log);
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
        $this->log("Stopping loop");
        $this->running = false;
        $this->freeTimers();
        return $this;
    }

    private function prepareActionForRuntime(string $triggerName, ...$args){
        /**
         * @var $action LoopAction
         */
        array_walk($this->loopActions, function(LoopAction $action) use($triggerName, $args) {
           if($action->trigger() !== $triggerName) return;
           $action->setRuntimeArgs(...$args);
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
            $this->log("Fork process child: %-5d", $pid);
            socket_close($pairs[1]);
            $read = null;
            $read = new \Event($this->eb, $pairs[0], \Event::READ | \Event::PERSIST, function ($fd, int $what, $args) use (&$read) {
                $this->_read($fd, $what, $read, $args);
            }, $pid);
            $write = new \Event($this->eb, $pairs[0], \Event::WRITE, function ($fd, int $what, $args) use (&$write) {
                $this->_write($fd, $what, $write, $args);
            }, $pid);
            // This means to read from pid $pid use $read event, to write to pid use $write
            $this->thisProcessInfo->addChild(
                (new ProcessInfo($pid))
                    ->setLabels(...$labels)
            )->addPipe(new Pipe($pid, $pairs[0], $read, $write, ...$labels));
            $read->add();
            $this->log("Ended fork: %-5d", $pid);
            return $pid;
        } else {
            socket_close($pairs[0]);
            $this->eb->reInit();
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
            $this->log("Socket to parent : %d", \EventUtil::getSocketFd($pairs[1]));
            $read = new \Event($this->eb, $pairs[1], \Event::READ | \Event::PERSIST, function ($fd, int $what, $args) use (&$read) {
                $this->_read($fd, $what, $read, $args);
            }, posix_getppid());
            $read->add();
            $write = new \Event($this->eb, $pairs[1], \Event::WRITE, function ($fd, int $what, $args) use (&$write) {
                $this->_write($fd, $what, $write, $args);
            }, posix_getppid());
            $pInfo->addPipe(
                new Pipe(posix_getppid(), $pairs[1], $read, $write, ...$pInfo->getParentProcessInfo()->getLabels())
            );
            $this->thisProcessInfo = $pInfo;
            if (is_callable($childCallback)) {
                call_user_func($childCallback, $this);
            }

            $this->log("Entering loop is running: %d", $this->isRunning());
            self::loop();
        }
        return posix_getpid();
    }

    private function addAction(LoopAction ...$action): void
    {
        if (count($action) === 0) {
            return;
        }
        array_push($this->loopActions, ...$action);
    }

    private function setTriggerFlag(string $triggerName, bool $shouldTrigger){
        $this->triggers[posix_getpid()][$triggerName] = $shouldTrigger;
    }

    private function dispatchActions()
    {
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
        for($i = 0; $i < $nbActions; $i++){
            $action = $this->loopActions[$i];
            $trigger = $action->trigger();
            // $this->log("Trigger: %s", $trigger);
            // $this->log("Triggers: (%s)", implode(',', array_keys(array_filter($this->triggers, function($flag){ return $flag; }))));
            if(!array_key_exists($cpid, $this->triggers) || !array_key_exists($trigger, $this->triggers[$cpid]) || $this->triggers[posix_getpid()][$trigger] === false){
                // Do nothing and keep action in stack
                continue;
            }
            $this->log("Total: %-3d => Dispatch action for trigger: %s", $nbActions, $trigger);
            $action->invoke($this, ...$action->getRuntimeArgs());
            $triggersToDisable[$trigger] = false;
            if(!$action->isPersistent()){
                unset($action[$i]);
            }
        }
        $this->loopActions = array_values($this->loopActions);

        array_walk($triggersToDisable, function($triggerFlag, $triggerName){
            $this->setTriggerFlag($triggerName, $triggerFlag);
        });
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
     * Detach this process from its controlling terminal.
     */
    public function detach(): Loop
    {
        posix_setsid();
        return $this;
    }


    public function setExitCode(int $exitCode = 0): Loop {
        if($exitCode < 0 || $exitCode >= 255){
            throw new \RuntimeException("Exit code must be between 0 and 254. 255 is reserved for PHP");
        }
        $this->exitCode = $exitCode;
        return $this;
    }

    private function shutdown(){
        array_walk($this->getProcessInfo()->getChildren(), function(ProcessInfo $childInfo){
            $this->log("Sending SIGTERM to %-5d", $childInfo->getPid());
            posix_kill($childInfo->getPid(), SIGTERM);
        });
    }

    public function loop(): void
    {
        if($this->thisProcessInfo->isRootOfHierarchy()){
            $this->log("Started master process");
        }
        while($this->running) {
            // $this->log("Running loop");
            $this->eb->loop(\EventBase::LOOP_ONCE);
            $this->prepareActionForRuntime(LoopAction::LOOP_ACTION_BEFORE_DISPATCH);
            $this->setTriggerFlag(LoopAction::LOOP_ACTION_BEFORE_DISPATCH, true);
            $this->dispatchActions();
        }
        $this->log("Loop exited, block until last child has exited");
        if($this->thisProcessInfo->hasChildren()){
            $this->shutdown();
            $status = null;
            $rusage = [];
            $this->log('Number of children: %-3d', count($this->thisProcessInfo->getChildren()));
            while($this->thisProcessInfo->hasChildren() && ($pid = pcntl_wait($status, 0)) > 0){
                $this->log("Pfff, we have avoided a SIGSEGV due to possible defered signal handler for pid: %-5d", $pid);
                $this->_handleWaitPid($pid, $status, $rusage);
                $this->dispatchActions();
            }
        }
        $this->log("All children exited");
        $this->thisProcessInfo->free();
        $this->eb->free();
        $this->log("Bye bye");
        if(!$this->thisProcessInfo->isRootOfHierarchy()){
            exit($this->exitCode);
        }
    }
}
