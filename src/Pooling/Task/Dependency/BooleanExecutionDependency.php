<?php


namespace Loop\Pooling\Task\Dependency;


use Loop\Pooling\ProcessPool;
use Loop\Pooling\Task\Task;

abstract class BooleanExecutionDependency implements TaskExecutionDependency
{
    protected $returnOn;
    protected $operands;

    public function __construct(TaskExecutionDependency ...$operands)
    {
        $this->operands = $operands;
    }


    public function onTaskExecuted(ProcessPool $pool): void
    {
        // TODO: Implement onTaskExecuted() method.
    }

    function isFullfill(ProcessPool $pool, Task $task): bool
    {
        $i = 0;
        $nbOperands = count($this->operands);
        if($this->returnOn === $this->operands[$i++]->isFullfill($pool, $task)){
            return $this->returnOn;
        }
        for(; $i < $nbOperands; $i++){
            if($this->operands[$i++]->isFullfill($pool, $task) === $this->returnOn){
                return $this->returnOn;
            }
        }
        return !$this->returnOn;
    }
}
