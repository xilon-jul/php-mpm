<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\ProcessInfo;
use Loop\Protocol\ProcessResolutionProtocolMessage;
use Loop\Protocol\ProtocolMessage;

require_once __DIR__.'/../vendor/autoload.php';


$loop = new \Loop\Core\Loop();

$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, true,
    function(Loop $loop, ProtocolMessage $message){
        fprintf(STDOUT, "In process: %d : received message : %s\n", $loop->getProcessInfo()->getPid(), $message->getField('data')->getValue());
        $loop->stop();
    });


$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED, true, true, function(Loop $loop, ProcessInfo $processInfo){
    fprintf(STDOUT, "In process: %d : process has terminated: %s\n", posix_getpid(), $processInfo);
});

$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, true, true, function(Loop $loop, ProcessInfo $processInfo){
    fprintf(STDOUT, "In process: %d : process has terminated\n", posix_getpid());
});
/*
$loop->addPeriodTimer(1, 5, function() use($loop, $pid) {
    $loop->fork(null, 'group1');
});
*/
$loop->detach();

$loop->fork(null, 'group1');
$loop->fork(null, 'group1');
$loop->fork(null, 'group1');
$loop->fork(null, 'group1');
//$loop->fork(null, 'group1');



$loop->addPeriodTimer(1, -1, function() use($loop, $pid) {
    $message = new ProcessResolutionProtocolMessage();
    $message->getField('data')->setValue('foo');
    $message->getField('destination_label')->setValue('group1');

    $loop->submit($message);
});

$loop->loop();


