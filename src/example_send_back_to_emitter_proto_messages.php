<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Protocol\ProcessResolutionProtocolMessage;

require_once __DIR__.'/../vendor/autoload.php';

/*/

This is the process tree :

            P0
           /  \
          P1  P2
          |
          P3

This example demonstrates how the master process can send a message to all of its children every 3 seconds.
Each process displays the message payload to stdout.
/*/
$loop = new \Loop\Core\Loop();
$loop->setLoggingEnabled(false);

$exampleMessage = new ProcessResolutionProtocolMessage();
$exampleMessage->getField('destination_label')->setValue('group');
$exampleMessage->getField('data')->setValue('Test message');

$loop->addPeriodTimer(3, function(Loop $loop) use($exampleMessage) {
    $loop->submit($exampleMessage);
}, 1, true);

// Register this action for master process only
$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, false, function(Loop $loop, ...$messages) {
    foreach($messages as $m){
        fprintf(STDOUT, 'In pid: %5d - Received back message: %s from %5d %s', $loop->getProcessInfo()->getPid(), $m->getField('data')->getValue(), $m->getField('source_pid')->getValue(), PHP_EOL);
    }
}, function(){
    return false;
});

// This action is registered for all processes
$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, false, function(Loop $loop, ...$messages){
    if($loop->getProcessInfo()->isRootOfHierarchy()){

        return;
    }
    fprintf(STDOUT, 'Processing message in process %5d %s', $loop->getProcessInfo()->getPid(), PHP_EOL);
    /**
     * @var m ProcessResolutionProtocolMessage
     */
    $pong = new ProcessResolutionProtocolMessage();
    foreach($messages as $m){
        $pong->getField('destination_pid')->setValue($m->getField('source_pid')->getValue());
        $pong->getField('data')->setValue('Pong : ' . $m->getField('data')->getValue());
        $loop->submit($pong);
    }

});

$loop->fork(function($loop){
    $loop->fork(null, 'group');
}, 'group');

$loop->fork(null, 'group');

$loop->loop();
