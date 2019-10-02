<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-06
 * Time: 00:08
 */

namespace Loop\Pooling\Task;


use Loop\Pooling\Task\Dependency\TaskExecutionDependency;

abstract class AbstractDefaultTask implements Task
{
    private $dependencies = [];
    private $name;

    public function __construct(string $name, TaskExecutionDependency ...$dependencies)
    {
        $this->name = $name;
        $this->addDependencies($dependencies);
    }

    public function addDependencies(TaskExecutionDependency ...$dependencies): void {
        array_push($this->dependencies, ...$dependencies);
    }

    public function getDependencies(): array {
        return $this->dependencies;
    }

    /**
     * @return string the task name
     */
    public function name(): string {
        return $this->name;
    }

    /**
     * Task runtime code executed inside a process
     * @return TaskResult|null the task result
     */
    abstract function execute(): ?TaskResult;
}
