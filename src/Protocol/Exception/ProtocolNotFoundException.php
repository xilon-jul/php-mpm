<?php
namespace Loop\Protocol\Exception;

class ProtocolNotFoundException extends ProtocolException
{

    private $protocolId;

    public function __construct(int $protocolId, string $message = "", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->protocolId = $protocolId;
    }

    /**
     * @return int
     */
    public function getProtocolId(): int
    {
        return $this->protocolId;
    }
}
