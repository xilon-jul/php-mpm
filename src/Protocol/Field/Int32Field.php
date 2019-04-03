<?php
namespace Loop\Protocol\Field;


use Loop\Protocol\Exception\ProtocolReadException;

class Int32Field extends ProtocolField {

    public function __construct()
    {
        parent::__construct(ProtocolField::FIELD_TYPE_INT32);
    }

    public function doPack(): string
    {
        return pack('V', (int) $this->value);
    }

    public function doUnpack(string &$bytes): ProtocolField
    {
        if(strlen($bytes) < 4){
            throw new ProtocolReadException("Not enough bytes to read protocol int32 type field");
        }
        $this->value = (int) unpack('Vint', $bytes)['int'];
        $bytes = substr($bytes, 4);
        return $this;
    }

    public function isEmpty(): bool
    {
        return !is_int($this->value) || $this->value === null;
    }
}