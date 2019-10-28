<?php


namespace Loop\Pooling\Task;


class TaskResult
{

    const STATUS_SUCCESS = true;
    const STATUS_FAILED = false;

    private $status;
    private $result;

    /**
     * TaskResult constructor.
     * @param $result
     */
    public function __construct(bool $executionStatus, $result)
    {
        $this->status = $executionStatus;
        $this->result = $result;
    }

    public function getStatus(): bool {
        return $this->status;
    }

    public function isSuccessful(): bool {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * @return mixed
     */
    public function getResult()
    {
        return $this->result;
    }
}
