<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-11
 * Time: 15:06
 */

namespace Loop\Pooling\Strategy;


use Loop\Pooling\ProcessPool;

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
