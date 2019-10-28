<?php


namespace Loop\Pooling;


use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\Message\ProcessResolutionProtocolMessage;
use Loop\Core\ProcessInfo;
use Loop\Pooling\Strategy\ProcessPoolLifecycleStrategy;
use Loop\Pooling\Strategy\TaskDistributionStrategy;
use Loop\Pooling\Strategy\WorkerEvent;
use Loop\Pooling\Task\Dependency\TaskExecutionDependency;
use Loop\Pooling\Task\Task;
use Loop\Pooling\Task\TaskAggregate;
use Loop\Util\Logger;

class ProcessPool
{

    public static $CONTEXT = 'pool';

    /**
     * @var ProcessPoolLifecycleStrategy $processPoolLifecycleStrategy
     */
    private $processPoolLifecycleStrategy;

    /**
     * @var TaskDistributionStrategy $taskDistributionStrategy
     */
    private $taskDistributionStrategy;

    /**
     * @var $taskQueue TaskAggregate[]
     */
    private $taskQueue = [];


    private $taskRunning = [];


    private $taskTerminated = [];


    /**
     * @var Loop $loop
     */
    private $loop;

    public function __construct(ProcessPoolLifecycleStrategy $processPoolLifecycleStrategy, TaskDistributionStrategy $taskDistributionStrategy)
    {
        $this->loop = new Loop();
        $this->taskDistributionStrategy = $taskDistributionStrategy;
        $this->processPoolLifecycleStrategy = $processPoolLifecycleStrategy;
    }

    /**
     * Adds one or more tasks to the execution queue
     * @param Task ...$tasks
     */
    public function submit(Task ...$tasks){
        $taskList = array_map(function(Task $t){
            return new TaskAggregate($t);
        }, $tasks);
        array_push($this->taskQueue, ...$taskList);
    }


    public function getTaskByStatus(int $taskStatus): array {
        return array_filter($this->taskTerminated, function(TaskAggregate $agg) use ($taskStatus) {
            return $agg->getTaskResult()->getStatus() === $taskStatus;
        });
    }


    public function getNumberTerminatedTask(): int {
        return count($this->taskTerminated);
    }

    public function getNumberRunningTask(): int {
        return count($this->taskRunning);
    }

    /**
     * Gets the number of queued task waiting to be executed
     * @return int
     */
    public function getNumberQueuedTask(): int {
        return count($this->taskQueue);
    }

    /**
     * Checks whether all task dependencies are fullfilled
     * @param Task $task the task we want to check for deps
     * @return bool if all deps cond are met
     */
    private function canRunTask(Task $task): bool {
        $canTaskRun = true;
        array_walk($task->getDependencies(), function(TaskExecutionDependency $dependency) use (&$canTaskRun, $task){
            $canTaskRun &= $dependency->isFullfill($this, $task);
        });
        return $canTaskRun;
    }

    /**
     * Gets the number of available workers
     * @return int
     */
    public function getNumberOfAvailableWorkers(): int {
        return count(array_filter($this->loop->getProcessInfo()->getChildren(), function(ProcessInfo $p){
            return $p->isAvailable();
        }));
    }

    /**
     * Traverses the execution queue and for all task whose dependencies are fullfilled, give them to the distribution strategy
     * @throws \Loop\Protocol\Exception\ProtocolException
     */
    private function dispatch(): void {
        $nbQueuedTask = $this->getNumberQueuedTask();
        Logger::log(self::$CONTEXT, 'Number task (running | queued | terminated : %-3d | %-3d | %-3d )', count($this->taskRunning), $nbQueuedTask, count($this->taskTerminated));
        $it = 0;
        $this->processPoolLifecycleStrategy->onDispatchStart($this);
        while($it++ < $nbQueuedTask){
            Logger::log(self::$CONTEXT, 'Attempt to dispatch new task (before dispatch loop queued task = %d)', $nbQueuedTask);
            $taskAggregate = array_shift($this->taskQueue);
            if(!$this->canRunTask($taskAggregate->getTask())){
                // Add task at the end
                array_push($this->taskQueue, $taskAggregate);
                continue;
            }
            $worker = $this->taskDistributionStrategy->distribute(...array_values($this->loop->getProcessInfo()->getChildren()));
            // No worker is available, stop dispatch
            if(!$worker){
                Logger::log(self::$CONTEXT, 'No worker available');
                array_push($this->taskQueue, $taskAggregate);
                return;
            }
            $taskAggregate->setProcessInstance($worker);
            $worker->setAvailable(false);
            $this->taskRunning[] = $taskAggregate;
            // We have a worker available, send the task to elected worker
            $taskMessage = new ProcessResolutionProtocolMessage();
            $taskMessage->getField('data')->setValue(serialize($taskAggregate));
            $taskMessage->getField('destination_pid')->setValue($worker->getPid());
            $this->loop->submit($taskMessage);
        }
        $this->processPoolLifecycleStrategy->onDispatchEnd($this);
    }

    public function fork(): void {
        $this->loop->fork(function(Loop $childLoop){
            $childLoop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, true, function(Loop $loop, ProcessResolutionProtocolMessage ...$messages){
                foreach($messages as $m){
                    /**
                     * @var $task TaskAggregate
                     */
                    $task = unserialize($m->getField('data')->getValue());
                    $task->execute();
                    $statusBack = new ProcessResolutionProtocolMessage();
                    $statusBack->getField('destination_pid')->setValue($m->getField('source_pid')->getValue());
                    $statusBack->getField('data')->setValue(serialize($task));
                    $loop->submit($statusBack);
                }
            }, function(){ return false; });
        });
    }


    private function notifyStrategyChildEvent(int $type, ProcessInfo ...$list){
                foreach ($list as $worker){
                    $this->processPoolLifecycleStrategy->onChildEvent($this, new WorkerEvent($type, $worker));
                }
    }

    public function start(): void {
        // Handle task status back and update pool state
        $this->loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, true, function(Loop $loop, ProcessResolutionProtocolMessage ...$messages){
            // Master process receives a task result, lets update process pool stats

            foreach($messages as $m){
                /**
                 * @var $taskAggregate TaskAggregate
                 */
                $taskAggregate = unserialize($m->getField('data')->getValue());
                if(false === $taskAggregate){
                    Logger::log(ProcessPool::$CONTEXT, 'Failed to unserialize task in master process');
                    continue;
                }
                $task = $taskAggregate->getTask();
                // Remove task from running queue and add it to terminated queue
                $this->taskRunning = array_values(array_filter($this->taskRunning, function(TaskAggregate $agg) use($taskAggregate, $task) {
                   return ! ($agg->getTask()->name() ===  $task->name() && $taskAggregate->getInstanceId() === $agg->getInstanceId());
                }));
                $this->taskTerminated[] = $taskAggregate;
                // Mark worker available
                $this->loop->getProcessInfo()->getProcessInfo($taskAggregate->getProcessInstance()->getPid())->setAvailable(true);
                if($task->isPermanent()){
                    Logger::log(self::$CONTEXT, 'Adding back task %s', $task->name());
                    array_push($this->taskQueue, $taskAggregate);
                }
            }

        }, function(){
            return false;
        });

        $this->loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, true, false, function(Loop $loop,  ProcessInfo ...$processInfo){
            $this->notifyStrategyChildEvent(WorkerEvent::TERMINATED, ...$processInfo);
        });

        $this->loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_STOPPED, true, false, function(Loop $loop, ProcessInfo ...$processInfo){
            $this->notifyStrategyChildEvent(WorkerEvent::UNAVAILABLE, ...$processInfo);
        });

        $this->loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_CONTINUED, true, false, function(Loop $loop, ProcessInfo ...$processInfo){
            $this->notifyStrategyChildEvent(WorkerEvent::AVAILABLE, ...$processInfo);
        });

        $this->processPoolLifecycleStrategy->onPoolStart($this);

        $this->loop->addPeriodTimer($this->taskDistributionStrategy->getDispatchPeriod(), function(){
            $this->dispatch();
        }, -1, true);

        $this->loop->loop();
    }

}
