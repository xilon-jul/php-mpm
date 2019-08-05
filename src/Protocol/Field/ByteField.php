<?php
namespace Loop\Protocol\Field;


use Loop\Protocol\Exception\ProtocolReadException;
use Loop\Util\Logger;

class ByteField extends ProtocolField {

    public function __construct()
    {
        parent::__construct(ProtocolField::FIELD_TYPE_BYTE);
    }

    public function doPack(): string
    {
        $val = $this->value ? 1 : 0;
        Logger::log('bytefield', 'Packing value %c', $val);
        return pack('c', $val);
    }

    public function doUnpack(string &$bytes): ProtocolField
    {
        if(strlen($bytes) < 1){
            throw new ProtocolReadException("Not enough bytes to read protocol byte type field");
        }
        $this->value = (int) unpack('cchar', $bytes)['char'];
        $bytes = substr($bytes, 1);
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->value === null;
    }
}
