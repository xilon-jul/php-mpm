<?php
namespace Loop\Core\Message;

use Loop\Protocol\Field\ByteField;
use Loop\Protocol\Field\Int32Field;
use Loop\Protocol\Field\VariableBytesField;
use Loop\Protocol\ProtocolMessage;

class ProcessResolutionProtocolMessage extends ProtocolMessage {


    public function __construct()
    {
        parent::__construct();
        $this->addField((new VariableBytesField())->setName('destination_label'));
        $this->addField((new Int32Field())->setName("sent_at"));
        $this->addField((new ByteField())->setName("coalesce")->setRequired(
            false
        ));
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
