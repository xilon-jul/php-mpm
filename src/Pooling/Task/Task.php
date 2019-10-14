<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-06
 * Time: 00:08
 */

namespace Loop\Pooling\Task;


use Loop\Pooling\Task\Dependency\TaskExecutionDependency;

abstract class Task
{
    private $dependencies = [];
    private $name, $isPermanent;

    public function __construct(string $name, bool $permanent = false, TaskExecutionDependency ...$dependencies)
    {
        $this->name = $name;
        $this->isPermanent = $permanent;
        $this->addDependencies(...$dependencies);
    }

    public function permanent(): bool
    {
      return $this->isPermanent;
    }

    public function removeDependencies(TaskExecutionDependency $dependency): void {
        $this->dependencies = array_values(array_filter($this->dependencies, function($dep) use ($dependency) {
            return $dep === $dependency;
        }));
    }

    public function getDependency(string $dependencyClass): ?TaskExecutionDependency {
        foreach($this->dependencies as $dep){
            if (get_class($dep) === $dependencyClass){
                return $dep;
            }
        }
        return null;
    }

    /**
     * @param bool $isPermanent
     */
    public function setIsPermanent(bool $isPermanent): void
    {
        $this->isPermanent = $isPermanent;
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
     * @return bool
     */
    public function isPermanent(): bool
    {
        return $this->isPermanent;
    }

    /**
     * Task runtime code executed inside a process
     * @return TaskResult|null the task result
     */
    abstract function execute(): ?TaskResult;
}
