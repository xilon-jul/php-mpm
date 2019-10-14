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
use Loop\Util\Logger;

class PeriodicExecutionDependency implements TaskExecutionDependency
{
    private $periodicExpression;
    private $cronExpression;
    /**
     * @var $nextRunDate \DateTime
     */
    private $nextRunDate = null;

    public function __construct(string $periodicExpression, $runNow = true)
    {
        $this->periodicExpression = $periodicExpression;
        $this->cronExpression = CronExpression::factory($this->periodicExpression);

        $this->nextRunDate = $runNow ? $this->cronExpression->getPreviousRunDate() : $this->cronExpression->getNextRunDate();
    }

    public function onTaskExecuted(ProcessPool $pool): void
    {
        // TODO: Implement onTaskExecuted() method.
    }

    /**
     * @return string
     */
    public function getPeriodicExpression(): string
    {
        return $this->periodicExpression;
    }

    function isFullfill(ProcessPool $pool, Task $task): bool {
        $nowDate = new \DateTime('now', new \DateTimeZone('utc'));
        Logger::log(ProcessPool::$CONTEXT, 'Task %s next run date = %s', $task->name(), $this->nextRunDate->format('Y-m-d H:i:s'));
        if($nowDate->getTimestamp() <=  $this->nextRunDate->getTimestamp()){
            return false;
        }
        $this->nextRunDate = $this->cronExpression->getNextRunDate($nowDate);
        return $this->cronExpression->isDue();
    }

    public function isPermanent(): bool
    {
        return true;
    }

}
