<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-11
 * Time: 10:42
 */

namespace Loop\Pooling\Strategy;


use Loop\Core\ProcessInfo;

interface TaskDistributionStrategy
{
    function distribute(ProcessInfo ...$processList): ?ProcessInfo;

    function getDispatchPeriod(): float ;
}
