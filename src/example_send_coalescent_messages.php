<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\Message\ProcessResolutionProtocolMessage;
use Loop\Util\Logger;

require_once __DIR__.'/../vendor/autoload.php';

/*/

This is the process tree :

            P0
           /
          P1

Every 1/2 seconds, the master process send a message 'test' to its only child. The child it self simulates a task that takes 4 seconds.
If a message was not coalescent, the child process would receive 8 messages at each dispatch, otherwise it only receives a single message.
The simple message on the contrary is not coalescent, so at each dispatch, the child process receives 8 of its occurences
/*/

Logger::disable();
$loop = new \Loop\Core\Loop();

$coalescentMessage = new ProcessResolutionProtocolMessage();
$coalescentMessage->getField('destination_label')->setValue('group');
$coalescentMessage->getField('data')->setValue('Test message');
$coalescentMessage->getField('coalesce')->setValue(1);

$simpleMessage = new ProcessResolutionProtocolMessage();
$simpleMessage->getField('destination_label')->setValue('group');
$simpleMessage->getField('data')->setValue('Simple message');



$loop->addPeriodTimer(0.5, function(Loop $loop) use($coalescentMessage, $simpleMessage) {
   $loop->submit($coalescentMessage);
    $loop->submit($simpleMessage);
}, -1, true);


$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, false, function(Loop $loop, ...$messages){
    // fprintf(STDOUT, 'In process %5d : received %d messages %s', $loop->getProcessInfo()->getPid(), count($messages), PHP_EOL);
    /**
     * @var $m ProcessResolutionProtocolMessage
     */
    foreach($messages as $m){
        fprintf(STDOUT, 'In process %5d : Message data : %s %s', $loop->getProcessInfo()->getPid(), $m->getField('data')->getValue(), PHP_EOL);
    }
    sleep(4);
});

$loop->fork(null, 'group');

$loop->loop();
