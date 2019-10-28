<?php

namespace Loop\Pooling\Strategy;


use Loop\Pooling\ProcessPool;
use Loop\Util\Logger;

class FixedPoolStrategy implements ProcessPoolLifecycleStrategy
{

    private $startupProcesses = 5;

    /**
     * FixedPoolStrategy constructor.
     * @param int $startupProcesses
     */
    public function __construct(int $startupProcesses)
    {
        $this->startupProcesses = $startupProcesses;
    }


    function onPoolStart(ProcessPool $processPool): void
    {
        for($i = 0; $i < $this->startupProcesses; $i++){
            $processPool->fork();
        }
    }

    function onChildEvent(ProcessPool $pool, WorkerEvent $event): void
    {
        Logger::log(ProcessPool::$CONTEXT, '%s %s', __FUNCTION__, $event);
    }

    function onDispatchStart(ProcessPool $processPool): void
    {
        // TODO: Implement onDispatchStart() method.
    }

    function onDispatchEnd(ProcessPool $processPool): void
    {
        // TODO: Implement onDispatchEnd() method.
    }
}
