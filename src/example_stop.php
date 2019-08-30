<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\ProcessInfo;

require_once __DIR__.'/../vendor/autoload.php';

/*/

This is the process tree :

            P0
           /  \
          P1  P2

This example demonstrates how the master process can send a message to all of its children every 3 seconds.
Each process displays the message payload to stdout.
/*/
$loop = new \Loop\Core\Loop();


$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, true, false, function(Loop $loop, ProcessInfo ...$processes) {
    foreach($processes as $p){
        fprintf(STDOUT, "Child %d terminated%s", $p->getPid(), PHP_EOL);
    }
});


$loop->addPeriodTimer(3, function(Loop $loop) use($exampleMessage) {
   $loop->stop();
}, 1, false);

$loop->fork(function(Loop $loop){
    $loop->stop();
});
$loop->fork(function(Loop $loop){
    $loop->fork(function(Loop $loop){
        $loop->stop();
    });
    $loop->stop();
});

$loop->loop();

