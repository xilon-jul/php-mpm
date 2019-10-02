<?php

use Loop\Pooling\ProcessPool;
use Loop\Pooling\Strategy\FirstAvailableDispatchStrategy;
use Loop\Pooling\Strategy\FixedPoolStrategy;
use Loop\Pooling\Task\AbstractDefaultTask;
use Loop\Pooling\Task\TaskResult;

require_once __DIR__.'/../vendor/autoload.php';

class TestTask extends AbstractDefaultTask {

    public function execute(): ?TaskResult
    {
        echo 'Task '.PHP_EOL;
        sleep(2);
        return null;
    }
};


$processPool = new ProcessPool(new FixedPoolStrategy(5), new FirstAvailableDispatchStrategy(0.5));

for($i = 0; $i < 100; $i++){
    $processPool->submit(new TestTask('test'));
}

$processPool->start();

