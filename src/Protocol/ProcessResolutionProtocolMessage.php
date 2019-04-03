<?php
namespace Loop\Protocol;

use Loop\Protocol\Field\BitField;
use Loop\Protocol\Field\Int32Field;
use Loop\Protocol\Field\VariableBytesField;
use Loop\Protocol\Field\WrapProtocolField;

class ProcessResolutionProtocolMessage extends ProtocolMessage {


    public function __construct()
    {
        parent::__construct();
        $this->addField((new VariableBytesField())->setName('destination_label'));
        $this->addField((new Int32Field())->setName('destination_pid'));
        $this->addField((new Int32Field())->setName('broadcast')
            ->setRequired(false)
        );
        $this->addField((new Int32Field())->setName('source_pid')
            ->setIsProtected(true)
        );
        $this->addField((new Int32Field())->setName('previous_pid')
            ->setRequired(false)
            ->setIsProtected(true)
        );
        $this->addField((new VariableBytesField())->setName('data'));
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getId(): int
    {
        return 80;
    }
}
