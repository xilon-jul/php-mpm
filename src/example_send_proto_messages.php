<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Util\Logger;

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

Logger::disable();
$loop = new \Loop\Core\Loop();

$exampleMessage = new \Loop\Core\Message\ProcessResolutionProtocolMessage();
$exampleMessage->getField('destination_label')->setValue('group');
$exampleMessage->getField('data')->setValue('Test message');

$loop->addPeriodTimer(3, function(Loop $loop) use($exampleMessage) {
   $loop->submit($exampleMessage);
}, -1, true);


$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, false, function(Loop $loop, ...$messages){
    // fprintf(STDOUT, 'In process %5d : received %d messages %s', $loop->getProcessInfo()->getPid(), count($messages), PHP_EOL);
    /**
     * @var $m ProcessResolutionProtocolMessage
     */
    foreach($messages as $m){
        fprintf(STDOUT, 'In process %5d : Message data : %s %s', $loop->getProcessInfo()->getPid(), $m->getField('data')->getValue(), PHP_EOL);
    }
});


$loop->fork(function($loop){
    $loop->fork(null, 'group');
}, 'group');

$loop->fork(null, 'group');

$loop->loop();
