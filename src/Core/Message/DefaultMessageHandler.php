<?php
namespace Loop\Core\Message;

use Loop\Core\ProcessInfo;
use Loop\Protocol\ProtocolMessage;


abstract class DefaultMessageHandler implements MessageHandler {

    function guardMessageIsValid(ProtocolMessage $message): void {
        // Do nothing
    }

    abstract function getPipeCandidates(ProtocolMessage $message, ProcessInfo $processInfo): array;

    function preSubmit(ProtocolMessage $message): void {
        // Do nothing
    }

}
