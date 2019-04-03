<?php
namespace Loop\Protocol\Field;


use Loop\Protocol\Exception\ProtocolReadException;

class ByteField extends ProtocolField {

    public function __construct()
    {
        parent::__construct(ProtocolField::FIELD_TYPE_BYTE);
    }

    public function doPack(): string
    {
        return pack('c', chr($this->value));
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
        return !is_string($this->value) || $this->value === null;
    }
}