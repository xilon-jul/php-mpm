<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-10-02
 * Time: 11:31
 */

namespace Loop\Pooling\Task\Dependency;

use Cron\CronExpression;
use Loop\Pooling\ProcessPool;
use Loop\Pooling\Task\Task;

class PeriodicExecutionDependency implements TaskExecutionDependency
{
    private $periodicExpression;

    public function __construct(?string $periodicExpression)
    {
        $this->periodicExpression = $periodicExpression;
    }

    function isFullfill(ProcessPool $pool, Task $task): bool {
        return null === $this->periodicExpression ? true : CronExpression::factory($this->periodicExpression)->isDue();
    }
}
