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
use Loop\Pooling\Strategy\ProcessPoolLifecycleStrategy;
use Loop\Pooling\Strategy\TaskDistributionStrategy;
use Loop\Pooling\Task\Task;
use Loop\Pooling\Task\TaskAggregate;
use Loop\Protocol\ProcessResolutionProtocolMessage;

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

    public function submit(Task ...$tasks){
        $taskList = array_map(function(Task $t){
            return new TaskAggregate($t);
        }, $tasks);
        array_push($this->taskQueue, ...$taskList);
    }

    public function getQueuedTask(): int {
        return count($this->taskQueue);
    }

    private function getDueTask(): array {
        return array_filter($this->taskQueue, function(TaskAggregate $taskAggregate){
            $task = $taskAggregate->getTask();
            return !$task->isPeriodic() ||
                CronExpression::factory($task->periodicity())->isDue();
        });
    }

    /**
     * Retrieves the task that are due at current date, and try to dispatch
     * them to available workers
     * @throws \Loop\Protocol\Exception\ProtocolException
     */
    private function dispatch(): void {
        $this->processPoolLifecycleStrategy->onPreDispatch($this);
        /**
         * @var $taskAggregate TaskAggregate
         */
        while(($taskAggregate = array_shift($this->taskQueue) !== false)){
            $this->processPoolLifecycleStrategy->onTaskPreSubmit($this);
            $worker = $this->taskDistributionStrategy->distribute(array_values($this->loop->getProcessInfo()->getChildren()));
            // No worker is available, stop dispatch
            if(!$worker){
                array_push($this->taskQueue, $taskAggregate);
                return;
            }

            $taskAggregate->setProcessInstance($worker);
            $worker->setAvailable(false);
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
                $this->taskTerminated[] = $taskAggregate;
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

    public function getTaskByStatus(int $taskStatus): array {
        return array_filter($this->taskTerminated, function(TaskAggregate $agg) use ($taskStatus) {
           return $agg->getTaskResult()->getStatus() === $taskStatus;
        });
    }
}
