<?php
namespace Loop\Protocol\Field\Factory;

use Loop\Protocol\DumpProtocol;
use Loop\Protocol\DumpPrototypeMessage;
use Loop\Protocol\Factory\ProtocolMessageFactory;
use Loop\Protocol\Field\ByteField;
use Loop\Protocol\Field\Int32Field;
use Loop\Protocol\Field\ProtocolField;
use Loop\Protocol\Field\VariableBytesField;
use Loop\Protocol\ProtocolBuilder;
use Loop\Protocol\ProtocolMessage;

final class FieldFactory {

    private function __construct()
    {
    }

    public static function getInstance(): FieldFactory {
        if(self::$instance === null){
            self::$instance = new FieldFactory();
        }
        return self::$instance;
    }

    public function get(int $type, ?int $protocolId = null, ?int $protocolVersion = null): ProtocolField {
        switch ($type){
            case ProtocolField::FIELD_TYPE_INT32:
                return new Int32Field;
            case ProtocolField::FIELD_TYPE_VARIABLE_BYTES:
                return new VariableBytesField;
            case ProtocolField::FIELD_TYPE_WRAP_PROT:
                if($protocolId === null){
                    return new class extends ProtocolMessage {
                        public function getVersion(): int
                        {
                            throw new \Exception("Dump protocol has no version");
                        }

                        public function getId(): int
                        {
                            throw new \Exception("Dump protocol has no id");
                        }
                    };
                }
                return ProtocolMessageFactory::getInstance()->getRegisteredProtocol($protocolId, $protocolVersion);
            case ProtocolField::FIELD_TYPE_BYTE:
                return new ByteField;
            default:
                throw new \InvalidArgumentException("No such field type " . $type);
        }
    }
}