<?php
namespace Loop\Protocol\Field;


use Loop\Protocol\Exception\ProtocolException;
use Loop\Protocol\Exception\ProtocolReadException;

class VariableBytesField extends ProtocolField {

    public function __construct()
    {
        parent::__construct(ProtocolField::FIELD_TYPE_VARIABLE_BYTES);
    }

    public function doPack(): string
    {
        $bytesLen = strlen($this->value);
        return pack(sprintf('Va%d', $bytesLen), $bytesLen, $this->value);
    }

    public function doUnpack(string &$bytes): ProtocolField
    {
        $nbBytes = strlen($bytes);
        if($nbBytes < 4){
            throw new ProtocolReadException("Not enough bytes to read protocol fields length");
        }
        $len = unpack('Vlen', $bytes)['len'];

        if($nbBytes < 4 + $len){
            throw new ProtocolReadException("Not enough bytes to read protocol fields variable data");
        }
        $data = unpack(sprintf('Vlen/a%dvalue', $len), $bytes);
        $this->value = $data['value'];
        $bytes = substr($bytes, 4 + $len);
        return $this;
    }

    protected function isEmpty(): bool
    {
        return !is_string($this->value) || $this->value === null;
    }
}