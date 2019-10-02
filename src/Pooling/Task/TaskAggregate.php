<?php
namespace Loop\Pooling\Task;


use Loop\Core\ProcessInfo;

final class TaskAggregate
{
    private $startedAt;
    private $endedAt;
    private $submittedAt;
    private $task;
    private $taskResult;
    private $instanceId;
    private $processInstance;

    public function __construct(Task $task)
    {
        $this->task = $task;
        $this->instanceId = uniqid();
        $this->submittedAt = new \DateTime('now', new \DateTimeZone('utc'));
    }

    /**
     * @param mixed $processInstance
     */
    public function setProcessInstance(ProcessInfo $processInstance): void
    {
        $this->processInstance = $processInstance;
    }

    /**
     * @return Task
     */
    public function getTask(): Task
    {
        return $this->task;
    }

    /**
     * @return mixed
     */
    public function getProcessInstance(): ProcessInfo
    {
        return $this->processInstance;
    }

    /**
     * @return string
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * @return mixed
     */
    public function getEndedAt(): \DateTime
    {
        return $this->endedAt;
    }

    /**
     * @return mixed
     */
    public function getStartedAt(): \DateTime
    {
        return $this->startedAt;
    }

    /**
     * @return \DateTime
     */
    public function getSubmittedAt(): \DateTime
    {
        return $this->submittedAt;
    }

    /**
     * @return mixed
     */
    public function getTaskResult(): ?TaskResult
    {
        return $this->taskResult;
    }

    public function execute(): void {
        $this->startedAt = new \DateTime('now', new \DateTimeZone('utc'));
        try {
            $this->taskResult = $this->task->execute();
        }
        catch (\Exception $e){
            $this->taskResult = new TaskResult(TaskResult::STATUS_FAILED, $e);
        }
        $this->endedAt = new \DateTime('now', new \DateTimeZone('utc'));
    }

}
