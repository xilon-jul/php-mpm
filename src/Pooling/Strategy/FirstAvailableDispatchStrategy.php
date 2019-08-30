<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-11
 * Time: 14:17
 */

namespace Loop\Pooling\Strategy;


use Loop\Core\ProcessInfo;

class FirstAvailableDispatchStrategy implements TaskDistributionStrategy
{
    private $dispatchPeriod = 0.5;


    public function __construct(float $dispatchPeriod)
    {
        $this->dispatchPeriod = $dispatchPeriod;
    }

    public function distribute(ProcessInfo ...$processList): ?ProcessInfo
    {
        foreach ($processList as $processInfo){
            if($processInfo->isAvailable()){
                return $processInfo;
            }
        }
        return null;
    }

    public function getDispatchPeriod(): float
    {
        return $this->dispatchPeriod;
    }

}
