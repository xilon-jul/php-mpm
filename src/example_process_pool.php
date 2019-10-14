<?php

use Loop\Pooling\ProcessPool;
use Loop\Pooling\Strategy\FirstAvailableDispatchStrategy;
use Loop\Pooling\Strategy\FixedPoolStrategy;
use Loop\Pooling\Task\Dependency\PeriodicExecutionDependency;
use Loop\Pooling\Task\Task;
use Loop\Pooling\Task\TaskResult;
use Loop\Util\Logger;

require_once __DIR__.'/../vendor/autoload.php';


Logger::disable();
Logger::enableContexts(ProcessPool::$CONTEXT);


class TestTask extends Task {

    public function execute(): ?TaskResult
    {
        echo 'Test task '.PHP_EOL;
        return null;
    }
};
class PrintTask extends Task {

    public function execute(): ?TaskResult
    {
        echo 'Cronified task with expression '.  $this->getDependency(PeriodicExecutionDependency::class)->getPeriodicExpression(). ' at '. date('d/m/y H:i:s').PHP_EOL;
        return null;
    }
};

$processPool = new ProcessPool(new FixedPoolStrategy(10), new FirstAvailableDispatchStrategy(0.5));

$processPool->submit(new PrintTask('periodic', true, new PeriodicExecutionDependency('*/1 * * * *')));

$processPool->submit(new PrintTask('periodic', true, new PeriodicExecutionDependency('*/2 * * * *')));


for($i = 0; $i < 100; $i++){
    $processPool->submit(new TestTask('test'));
}

$processPool->start();

