<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-05
 * Time: 18:40
 */

namespace Loop\Pooling;


use Cron\CronExpression;
use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\Message\ProcessResolutionProtocolMessage;
use Loop\Pooling\Strategy\ProcessPoolLifecycleStrategy;
use Loop\Pooling\Strategy\TaskDistributionStrategy;
use Loop\Pooling\Task\Dependency\TaskExecutionDependency;
use Loop\Pooling\Task\Task;
use Loop\Pooling\Task\TaskAggregate;

class ProcessPool
{

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
     * Traverses the execution queue and for all task whose dependencies are fullfilled, give them to the distribution strategy
     * @throws \Loop\Protocol\Exception\ProtocolException
     */
    private function dispatch(): void {
        $nbQueuedTask = $this->getNumberQueuedTask();
        $this->processPoolLifecycleStrategy->onPreDispatch($this);
        $it = 0;
        while($it++ < $nbQueuedTask){
            echo 'Try dispatch with counter ' . $it . PHP_EOL;
            $taskAggregate = array_shift($this->taskQueue);
            if(!$this->canRunTask($taskAggregate->getTask())){
                // Add task at the end
                array_push($this->taskQueue);
                continue;
            }
            $this->processPoolLifecycleStrategy->onTaskPreSubmit($this);
            $worker = $this->taskDistributionStrategy->distribute(...array_values($this->loop->getProcessInfo()->getChildren()));
            // No worker is available, stop dispatch
            if(!$worker){
                echo 'No worker available ....'.PHP_EOL;
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
            $this->processPoolLifecycleStrategy->onTaskPostSubmit($this);
        }
        $this->processPoolLifecycleStrategy->onPostDispatch($this);
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

    public function start(): void {
        // Handle task status back and update pool state
        $this->loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, true, function(Loop $loop, ProcessResolutionProtocolMessage ...$messages){
            // Master process receives a task result, lets update process pool stats

            foreach($messages as $m){
                /**
                 * @var $taskAggregate TaskAggregate
                 */
                $taskAggregate = unserialize($m->getField('data')->getValue());
                $this->taskRunning = array_values(array_filter($this->taskRunning, function(TaskAggregate $agg) use($taskAggregate) {
                   return ! ($agg->getTask()->name() ===  $taskAggregate->getTask()->name() && $taskAggregate->getInstanceId() === $agg->getInstanceId());
                }));
                $this->taskTerminated[] = $taskAggregate;
                $this->loop->getProcessInfo()->getProcessInfo($taskAggregate->getProcessInstance()->getPid())->setAvailable(true);
                $taskAggregate->getProcessInstance()->getPid();
                echo 'Task terminated ' . count($this->taskTerminated) . PHP_EOL;

            }

        }, function(){
            return false;
        });

        $this->processPoolLifecycleStrategy->onPoolStart($this);

        $this->loop->addPeriodTimer($this->taskDistributionStrategy->getDispatchPeriod(), function(){
            $this->dispatch();
        }, -1, true);

        $this->loop->loop();
    }

}
