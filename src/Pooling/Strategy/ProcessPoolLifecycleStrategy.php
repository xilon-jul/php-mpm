<?php
namespace Loop\Pooling\Strategy;

use Loop\Pooling\ProcessPool;

interface ProcessPoolLifecycleStrategy
{
    function onPoolStart(ProcessPool $processPool): void;

    function onDispatchStart(ProcessPool $processPool): void;

    function onDispatchEnd(ProcessPool $processPool): void;

    function onChildEvent(ProcessPool $pool, WorkerEvent $event): void;
}
