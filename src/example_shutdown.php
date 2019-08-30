<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\Message\ProcessResolutionProtocolMessage;
use Loop\Core\ProcessInfo;
use Loop\Util\Logger;

require_once __DIR__.'/../vendor/autoload.php';

/*/

This is the process tree :

            P0
           /  \
          P1  P2

This example demonstrates how the master process can send a message to all of its children every 3 seconds.
Each process displays the message payload to stdout.
/*/
Logger::disable();
$loop = new \Loop\Core\Loop();
$exampleMessage = new ProcessResolutionProtocolMessage();
$exampleMessage->getField('destination_label')->setValue('group');
$exampleMessage->getField('data')->setValue('Test message');

$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, true, false, function(Loop $loop, ProcessInfo ...$processes) {
    foreach($processes as $p){
        fprintf(STDOUT, "Child %d terminated%s", $p->getPid(), PHP_EOL);
    }
});


$loop->addPeriodTimer(3, function(Loop $loop) use($exampleMessage) {
   $loop->shutdown();
}, 1, false);

$loop->fork(function(Loop $loop){
    $loop->fork();
});
$loop->fork();
$loop->fork();

$loop->loop();
