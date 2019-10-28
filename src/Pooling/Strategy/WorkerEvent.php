<?php
namespace Loop\Pooling\Strategy;

use Loop\Core\ProcessInfo;

class WorkerEvent {

    const TERMINATED = 1;
    const UNAVAILABLE = 2;
    const AVAILABLE = 3;

    private $eventType;
    private $worker;

    public function __construct(int $eventType, ProcessInfo $worker)
    {
        $this->eventType = $eventType;
        $this->worker = $worker;
    }

    /**
     * @return int
     */
    public function getEventType(): int
    {
        return $this->eventType;
    }

    /**
     * @return ProcessInfo
     */
    public function getWorker(): ProcessInfo
    {
        return $this->worker;
    }

    private function toStringEventType(): string {
        switch ($this->eventType){
            case self::TERMINATED:
                return 'terminated';
            case self::AVAILABLE:
                return 'available';
            case self::UNAVAILABLE:
                return 'unavailable';
            default:
                return 'unknown';
        }
    }

    public function __toString()
    {
        return sprintf('worker: %s | event: %s', $this->getWorker(), $this->toStringEventType());
    }

}
