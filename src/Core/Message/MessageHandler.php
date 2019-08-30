<?php
namespace Loop\Core\Message;

use Loop\Core\ProcessInfo;
use Loop\Protocol\ProtocolMessage;

interface MessageHandler {

    function guardMessageIsValid(ProtocolMessage $message): void;

    function getPipeCandidates(ProtocolMessage $message, ProcessInfo $processInfo): array;

    function preSubmit(ProtocolMessage $message): void;

}
