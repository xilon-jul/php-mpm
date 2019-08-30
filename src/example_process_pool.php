<?php

use Loop\Pooling\ProcessPool;
use Loop\Pooling\Strategy\FirstAvailableDispatchStrategy;
use Loop\Pooling\Strategy\FixedPoolStrategy;
use Loop\Pooling\Task\Task;
use Loop\Pooling\Task\TaskResult;

require_once __DIR__.'/../vendor/autoload.php';

class TestTask implements Task {

    public function periodicity(): string
    {
        return '* * * * *';
    }

    public function execute(): ?TaskResult
    {
        echo 'Task '.PHP_EOL;
        sleep(2);
    }

    public function name(): string
    {
        return 'test task';
    }

    function isPeriodic(): bool
    {
        return true;
    }
};


$processPool = new ProcessPool(new FixedPoolStrategy(5), new FirstAvailableDispatchStrategy(0.5));

for($i = 0; $i < 1000; $i++){
    $processPool->submit(new TestTask());
}

$processPool->start();

