<?php
namespace Loop\Core\Synchronization\Exception;
class TimeoutException extends \RuntimeException {

	private $timeout;
	
	public function __construct($timeout) {
		parent::__construct(sprintf("Timeout %d exception", $timeout));
		$this->timeout = (int)$timeout;
	}
	
	public function getTimeoutVal(){
		return $this->timeout;
	}
	
	public function getErrno(){
		if(!is_long($this->timeout)){
			return PCNTL_EINVAL;
		}
		else {
			return PCNTL_EAGAIN;
		}
	}

}
