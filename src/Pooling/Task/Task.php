<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-06
 * Time: 00:08
 */

namespace Loop\Pooling\Task;


use Loop\Pooling\Task\Dependency\TaskExecutionDependency;

interface Task
{
    function addDependencies(TaskExecutionDependency ...$dependencies): void;


    function getDependencies(): array;

    /**
     * @return string the task name
     */
    function name(): string;

    /**
     * Task runtime code executed inside a process
     * @return TaskResult|null the task result
     */
    function execute(): ?TaskResult;
}
