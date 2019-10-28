<?php


namespace Loop\Pooling\Strategy;


use Loop\Core\ProcessInfo;

interface TaskDistributionStrategy
{
    function distribute(ProcessInfo ...$processList): ?ProcessInfo;

    function getDispatchPeriod(): float ;
}
