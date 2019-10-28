<?php


namespace Loop\Pooling\Task\Dependency;


use Loop\Pooling\ProcessPool;
use Loop\Pooling\Task\Task;

interface TaskExecutionDependency
{
    function isFullfill(ProcessPool $pool, Task $task): bool;

    function onTaskExecuted(ProcessPool $pool): void;
}
