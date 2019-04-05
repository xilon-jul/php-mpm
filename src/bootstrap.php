<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\ProcessInfo;
use Loop\Core\Synchronization\PosixSignalBarrier;
use Loop\Protocol\ProcessResolutionProtocolMessage;
use Loop\Protocol\ProtocolMessage;

require_once __DIR__.'/../vendor/autoload.php';


$loop = new \Loop\Core\Loop();
$loop->setLoggingEnabled(false);

$barrier = 1;
$barrier = new PosixSignalBarrier(2);


$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_PROCESS_TERMINATED, false, false, function(Loop $loop, ProcessInfo $info) use($barrier) {
    fprintf(STDOUT, "Action on termination\n");
    $barrier = null;
});

$loop->fork(function(Loop $childloop) use ($barrier) {
    $childloop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, true,
        function(Loop $loop, ProtocolMessage $message) use ($barrier) {
            //$barrier = unserialize($message->getField('data')->getValue());
            $slept = sleep(3);
            if(false === $slept || $slept > 0){
                fprintf(STDERR, "Failed to sleep : $slept\n");
            }
            $barrier->await();
            fprintf(STDOUT, "In process: %d : received message : %s\n", $loop->getProcessInfo()->getPid(), $message->getField('data')->getValue());
        });
}, 'group1');

$loop->fork(function(Loop $childloop) use ($barrier) {
    $childloop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, true,
        function(Loop $loop, ProtocolMessage $message) use ($barrier) {
            //$barrier = unserialize($message->getField('data')->getValue());
            $slept = sleep(6);
            if(false === $slept || $slept > 0){
                fprintf(STDERR, "Failed to sleep : $slept\n");
            }
            $barrier->await();
            fprintf(STDOUT, "In process: %d : received message : %s\n", $loop->getProcessInfo()->getPid(), $message->getField('data')->getValue());
        });
}, 'group1');


$loop->addPeriodTimer(1, 2, function() use($loop, $pid, $barrier) {
    $message = new ProcessResolutionProtocolMessage();
    //$message->getField('data')->setValue(serialize($barrier));
    $message->getField('data')->setValue("test");
    $message->getField('destination_label')->setValue('group1');

    $loop->submit($message);
});

$loop->loop();


