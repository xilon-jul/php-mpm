<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-10-02
 * Time: 11:31
 */

namespace Loop\Pooling\Task\Dependency;



use Loop\Pooling\ProcessPool;
use Loop\Pooling\Task\Task;

class BooleanOrExecutionDependency implements TaskExecutionDependency
{
    private $operands = [];

    public function __construct(TaskExecutionDependency ...$operands)
    {
        $this->operands = $operands;
    }

    function isFullfill(ProcessPool $pool, Task $task): bool {
        $i = 0;
        $nbOperands = count($this->operands);
        if(true === $this->operands[$i++]->isFullfill($pool, $task)){
            return true;
        }
        for(; $i < $nbOperands; $i++){
            if($this->operands[$i++]->isFullfill($pool, $task) === true){
                return true;
            }
        }
        return false;
    }
}
