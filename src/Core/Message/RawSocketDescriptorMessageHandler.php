<?php
namespace Loop\Core\Message;

use Loop\Core\Pipe;
use Loop\Core\ProcessInfo;
use Loop\Protocol\ProtocolMessage;

/**
 * Class RawSocketDescriptorMessageHandler allow to send message to pipes matching this file descriptor.
 * @package Loop\Core\Message
 */
class RawSocketDescriptorMessageHandler extends DefaultMessageHandler {

    private $fd;

    public function __construct($fd)
    {
        $this->fd = $fd;
    }

    public function getPipeCandidates(ProtocolMessage $message, ProcessInfo $processInfo): array
    {
        return array_filter($processInfo->getPipes(), function(Pipe $p){
            return $p->getFd() === $this->fd;
        });
    }
}
