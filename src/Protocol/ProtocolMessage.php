<?php
namespace Loop\Protocol;

use Loop\Protocol\Exception\ProtocolException;
use Loop\Protocol\Field\ProtocolField;

abstract class ProtocolMessage extends ProtocolField implements \Iterator {


    private $fields = [];
    private $lastExceptionCursor = -1;
    private $fieldCursor = 0;

    public function __construct()
    {
        parent::__construct(ProtocolField::FIELD_TYPE_WRAP_PROT);
    }

    protected function addField(ProtocolField $field): ProtocolMessage {
        $field->setPosition(count($this->fields));
        $this->fields[] = $field;
        return $this;
    }

    public function removeField(ProtocolField $field): ProtocolMessage {
        $this->fields = array_filter($this->fields, function(ProtocolField $f) use ($field) {
            return strcmp($field->getName(), $f->getName()) !== 0;
        });
        return $this;
    }

    public function &getFieldByPosition(int $pos): ?ProtocolField {
        /**
         * @var $field ProtocolField
         */
        foreach ($this->fields as &$field){
            if($field->getPosition() === $pos){
                return $field;
            }
        }
        unset($field);
        throw new ProtocolException(sprintf("Field at position %d does not exist in %s", $pos, get_class($this)));
    }

    public function &getField(string $name): ProtocolField {
        foreach ($this->fields as &$field){
            if(strcmp($field->getName(), $name) === 0){
                return $field;
            }
        }
        unset($field);
        throw new ProtocolException(sprintf("Field %s does not exist in %s", $name, get_class($this)));
    }

    public function setField(ProtocolField $field): void {
        $thisField = &$this->getField($field->getName());
        $thisField = $field;
    }

    public function doUnpack(string &$bytes): ProtocolField
    {
        return (new ProtocolBuilder)->read($bytes);
    }


    public function doPack(): string
    {
        return (new ProtocolBuilder)->toByteStream($this);
    }

    public function current()
    {
        return $this->fields[$this->fieldCursor];
    }

    public function key()
    {
        return $this->fieldCursor;
    }

    public function next()
    {
        $this->fieldCursor++;
    }

    public function rewind()
    {
        if($this->lastExceptionCursor > 0){
            $this->fieldCursor = $this->lastExceptionCursor;
            $this->lastExceptionCursor = -1;
            return;
        }
        $this->fieldCursor = 0;
    }

    public function valid()
    {
        return isset($this->fields[$this->fieldCursor]);
    }

    protected function isEmpty(): bool
    {
        foreach ($this->fields as $field){
            if(!$field->isEmpty()){
                return false;
            }
        }
        return true;
    }

    /**
     * @return int
     */
    final public function getLastExceptionCursor(): int
    {
        return $this->lastExceptionCursor;
    }

    /**
     * @param int $lastExceptionCursor
     */
    final public function setLastExceptionCursor(int $lastExceptionCursor)
    {
        $this->lastExceptionCursor = $lastExceptionCursor;
    }

    abstract public function getVersion(): int;

    abstract public function getId(): int;

    public function __toString()
    {
        $str = sprintf('%s: [ ', get_class($this));
        foreach ($this->fields as $index => $f){
            $str .= sprintf('%s, ', $f);
        }
        return substr($str, 0, -2) . ' ]';
    }
}
