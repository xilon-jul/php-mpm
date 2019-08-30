<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 29/06/2018
 * Time: 16:27
 */
namespace Loop\Protocol\Exception;

class ProtocolException extends \Exception
{
    public function __construct($message = "", $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
