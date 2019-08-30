<?php
namespace Loop\Protocol\Factory;

use Loop\Protocol\Exception\ProtocolNotFoundException;
use Loop\Protocol\ProtocolMessage;

final class ProtocolMessageFactory {

    /**
     * @var $instance ProtocolMessageFactory
     */
    static private $instance;

    private $protocols = [];

    private function __construct()
    {
    }

    public static function getInstance(): ProtocolMessageFactory {
        if(self::$instance === null){
            self::$instance = new ProtocolMessageFactory();
        }
        return self::$instance;
    }

    public function registerProtocol(ProtocolMessage $protocolMessage): ProtocolMessageFactory {
        $pclass = get_class($protocolMessage);
        if(isset($this->protocols[$protocolMessage->getId()]) && $this->protocols[$protocolMessage->getId()] === $pclass){
            throw new \InvalidArgumentException("Protocol with id " . $protocolMessage->getId() . " is already registered");
        }
        $this->protocols[$protocolMessage->getId()] = $pclass;
        return $this;
    }

    /**
     * Retrieve a registered protocol
     * @param int $id
     * @param int $version
     * @return ProtocolMessage
     * @throws ProtocolNotFoundException
     */
    public function getRegisteredProtocol(int $id, int $version): ProtocolMessage {
        if(!isset($this->protocols[$id])){
            throw new ProtocolNotFoundException($id, sprintf('No protocol found with id %d', $id));
        }
        return new $this->protocols[$id];
    }


    public function clear(){
        unset($this->protocols);
        $this->protocols = [];
    }

}
