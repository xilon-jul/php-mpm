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

interface TaskExecutionDependency
{
    function isFullfill(ProcessPool $pool, Task $task): bool;
}
