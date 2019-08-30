<?php
namespace Loop\Protocol;

use Loop\Protocol\Exception\ProtocolException;
use Loop\Protocol\Exception\ProtocolNotFoundException;
use Loop\Protocol\Exception\ProtocolReadException;
use Loop\Protocol\Factory\ProtocolMessageFactory;
use Loop\Protocol\Field\Factory\FieldFactory;
use Loop\Protocol\Field\Int32Field;
use Loop\Protocol\Field\ProtocolField;

final class ProtocolBuilder {

    const PROT_FIELD_ID = '_id';
    const PROT_FIELD_VERSION = '_version';
    const PROT_FIELD_DATALEN = '_len';

    /**
     * @var $callbacks array\array[string,callable]
     */
    private $callbacks;


    private $readBytes = -1;
    private $dataLen = 0;
    private $protocol = null;


    private function isProtocolHeaderField(ProtocolField $field): bool {
        $name = $field->getName();
        return $name === self::PROT_FIELD_VERSION || $name === self::PROT_FIELD_ID;
    }

    /**
     * @param callable $readCb
     */
    public function setReadCb(string $class, $readCb): ProtocolBuilder
    {
        $this->callbacks[$class] = $readCb;
        return $this;
    }

    public function toByteStream(ProtocolMessage $protocolMessage): string {
        $bytes = "";
        /**
         * @var $field ProtocolField
         */
        $bytes .= $this->pack((new Int32Field())->setName(self::PROT_FIELD_ID)->setValue($protocolMessage->getId())->setAnonymous(true));
        $bytes .= $this->pack((new Int32Field())->setName(self::PROT_FIELD_VERSION)->setValue($protocolMessage->getVersion())->setAnonymous(true));

        $dataBytes = '';
        foreach($protocolMessage as $index => $field){
            if($field->shouldBeIncluded()){
                $dataBytes .= $this->pack($field);
            }
        }
        $bytes .= $this->pack((new Int32Field())->setName(self::PROT_FIELD_DATALEN)->setValue(strlen($dataBytes))->setAnonymous(true));

        return $bytes . $dataBytes;
    }

    final private function getProtoHeaderLen(): int {
        static $len = 0;
        if($len !== 0) return $len;
        $dumpProtoMessage = new class extends ProtocolMessage {
            public function getVersion(): int
            {
                return 0;
            }
            public function getId(): int
            {
                return 0;
            }
        };
        $len = count($this->toByteStream($dumpProtoMessage));
        return $len;
    }

    final protected function pack(ProtocolField $field): string {
        $options = $field->getType() | ($field->isAnonymous() ? ProtocolField::FIELD_IS_ANONYMOUS : 0xFFFFFFFF);
        $format = 'cV';
        $args = [$options, $field->getPosition()];
        if(!$field->isAnonymous()){
            $len = strlen($field->getName());
            $format .= 'Va*';
            array_push($args, $len, $field->getName());
        }
        return pack($format, ...$args) . $field->doPack();
    }

    final protected function unpack(string &$bytes): ProtocolField {
        $nbBytes = strlen($bytes);
        if($nbBytes < 5){
            throw new ProtocolReadException("Not enough bytes to read protocol field header");
        }
        // Unpack first fith bytes
        $header = unpack('coptions/Vposition', $bytes);
        $fieldType = $header['options'] ^ ProtocolField::FIELD_IS_ANONYMOUS;
        $isAnonymous = $header['options'] & ProtocolField::FIELD_IS_ANONYMOUS;
        $fieldPosition = $header['position'];

        /**
         * @var $field ProtocolField
         */

        $field = FieldFactory::get($fieldType);

        $nextBytes = substr($bytes, 5);
        if(!$field->isAnonymous() && $nbBytes >= 9){
            $nameHeader = unpack('Vlen', $nextBytes);
            if($nbBytes < $nameHeader['len'] + 9) {
                throw new ProtocolReadException("Not enough bytes to read protocol field name");
            }
            $nameHeader = unpack($nextBytes, sprintf('Vlen/a%sname', $nameHeader['len']));
            $field->setName($nameHeader['name']);
            $nextBytes = substr($nextBytes, $nameHeader['len'] + 5);
        }
        try {
            $field = $field->doUnpack($nextBytes);
            $field->setAnonymous($isAnonymous);
            $field->setPosition($fieldPosition);
            $bytes = $nextBytes;
            return $field;
        }
        catch(ProtocolReadException $e){
            throw $e;
        }
    }

    private function readManyOrDie(string &$bytes, int $nbIteration): array {
        $bytesCopy = $bytes;
        try {
            $vars = [];
            for ($i = 0; $i < $nbIteration; $i++){
                $vars[] = $this->unpack($bytesCopy);
            }
            $bytes = $bytesCopy;
            return $vars;
        }
        catch (ProtocolException $e){
            // Do nothing but die
            throw $e;
        }
    }

    /**
     * @param string $bytes
     * @return ProtocolMessage
     * @throws Exception\ProtocolNotFoundException
     * @throws ProtocolException
     * @throws ProtocolReadException
     * @throws ProtocolNotFoundException
     */
    public function read(string &$bytes): ProtocolMessage {
        if($this->readBytes === -1){
            list($protocolId, $protocolVersion, $dataLen) = $this->readManyOrDie($bytes, 3);
            $this->protocol = ProtocolMessageFactory::getInstance()->getRegisteredProtocol((int)$protocolId->getValue(), (int)$protocolVersion->getValue());
            $this->dataLen = $dataLen->getValue();
            $this->readBytes = 0;
        }
        while($this->readBytes < $this->dataLen){
            $len = strlen($bytes);
            $valueField = $this->unpack($bytes);
            $this->readBytes += $len - strlen($bytes);
            if($valueField->isAnonymous()){
                $typeField = $this->protocol->getFieldByPosition($valueField->getPosition());
                $valueField->setName($typeField->getName());
            }
            $this->protocol->setField($valueField);
        }
        $callable = $this->callbacks[get_class($this->protocol)];
        if(is_callable($callable)){
            $callable($this->protocol);
        }
        $this->readBytes = -1;
        $this->dataLen = 0;
        return $this->protocol;
    }
}
