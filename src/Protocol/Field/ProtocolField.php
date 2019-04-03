<?php
namespace Loop\Protocol\Field;

use Loop\Protocol\Exception\ProtocolException;
use Loop\Protocol\Exception\ProtocolReadException;
use Loop\Protocol\ProtocolMessage;


abstract class ProtocolField {

    const FIELD_TYPE_VARIABLE_BYTES = 0;
    const FIELD_TYPE_INT32 = 1;
    const FIELD_TYPE_WRAP_PROT = 2;
    const FIELD_TYPE_BYTE = 4;

    const FIELD_IS_ANONYMOUS = 8;

    protected $isProtected = false;
    protected $position = -1;
    protected $required = true;
    protected $name;
    protected $value;
    protected $type;
    protected $anonymous = true;
    protected $raw = false;

    /**
     * ProtocolField constructor.
     * @param $name
     * @param $value
     * @param $type
     */
    protected function __construct(int $type)
    {
        $this->type = $type;
    }


    /**
     * @param bool $isProtected
     */
    public function setIsProtected(bool $isProtected): ProtocolField
    {
        $this->isProtected = $isProtected;
        return $this;
    }

    /**
     * @return bool
     */
    public function isProtected(): bool
    {
        return $this->isProtected;
    }

    /**
     * @param int $position
     */
    public function setPosition(int $position)
    {
        $this->position = $position;
        return $this;
    }

    /**
     * @return int
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * @param mixed $value
     */
    final public function setValue($value): ProtocolField
    {
        $this->value = $value;
        return $this;
    }

    /**
     * @param string $name
     */
    final public function setName(string $name): ProtocolField
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @param bool $required
     */
    final public function setRequired(bool $required): ProtocolField
    {
        $this->required = $required;
        return $this;
    }

    final public function shouldBeIncluded(): bool {
        if($this->isRequired()){
            return true;
        }
        return !$this->isEmpty();
    }

    /**
     * @return bool
     */
    final public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * @return bool
     */
    public function isRaw(): bool
    {
        return $this->raw;
    }

    /**
     * @param bool $raw
     */
    public function setRaw(bool $raw)
    {
        $this->raw = $raw;
        return $this;
    }

    /**
     * @return mixed
     */
    final public function getValue()
    {
        if($this instanceof ProtocolMessage){
           return $this;
        }
        return $this->value;
    }

    /**
     * @return int
     */
    final public function getType(): int
    {
        return $this->type;
    }

    /**
     * @return string
     */
    final public function getName(): ?string
    {
        return $this->name;
    }

    public function __toString()
    {
        return $this->getName().' = '.$this->getValue();
    }


    /**
     * @param bool $anonymous
     */
    public function setAnonymous(bool $anonymous)
    {
        $this->anonymous = $anonymous;
        return $this;
    }

    /**
     * @return bool
     */
    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    abstract public function doPack(): string;

    /**
     * Unpacks and drains bytes that were successfully unpacked. If there are not enough bytes to read, bytes should not be drained
     * @throws ProtocolException
     * @param string $bytes
     */
    abstract public function doUnpack(string &$bytes): ProtocolField;


    abstract protected function isEmpty(): bool ;
}
