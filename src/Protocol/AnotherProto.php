<?php
namespace Loop\Protocol;

use Loop\Protocol\Field\BitField;
use Loop\Protocol\Field\Int32Field;
use Loop\Protocol\Field\ProtocolField;
use Loop\Protocol\Field\VariableBytesField;
use Loop\Protocol\Field\WrapProtocolField;

class AnotherProto extends ProtocolMessage {


    public function __construct()
    {
        parent::__construct();
        $this->addField((new VariableBytesField())->setName('another_field'));
    }

    public function getId(): int
    {
        return 500;
    }

    public function getVersion(): int
    {
        return 12;
    }
}