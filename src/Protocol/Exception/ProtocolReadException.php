<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 29/06/2018
 * Time: 16:27
 */
namespace Loop\Protocol\Exception;

use Loop\Protocol\Field\ProtocolField;
use Throwable;

class ProtocolReadException extends ProtocolException
{


    /**
     * @var $field ProtocolField
     */
    private $field;


    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param ProtocolField $field
     */
    public function setField(ProtocolField $field)
    {
        $this->field = $field;
    }

    /**
     * @return ProtocolField
     */
    public function getField(): ?ProtocolField
    {
        return $this->field;
    }


}