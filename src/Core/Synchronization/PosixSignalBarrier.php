<?php
namespace Loop\Core\Synchronization;

use Loop\Core\Synchronization\Exception\BrokenBarrierException;
use Loop\Core\Synchronization\Exception\InterruptedException;
use Loop\Core\Synchronization\Exception\TimeoutException;

/**
 * A simple barrier synchronization mechanism that uses a shared memory counter
 * to synchronize processes and posix blocking sigwait mechanism to get notify when the barrier gets tripped.
 * The last process to reach the barrier notifies all the others. 
 * <b>For this barrier to work, you must install a signal handler for SIGUSR2 signals, this handler should not rely on a specific behavior, and in most cases should be empty</b>
 * 
 * Note that this object might also gets serialized and sent using IPC. The receiving process would invoked the __wakeup method
 * automatically and would be able to use the barrier as if it was created before a fork. 
 */
class PosixSignalBarrier implements BarrierInterface {

	/**
	 * Semaphore resource to synchronize process when accessing shared memory segment
	 * @var Resource
	 */
	private $semaphore;
	
	/**
	 * Shared memory segment resource
	 * @var Resource $shm
	 */
	private $shm;
	
	
	/**
	 * Temporary file created when the barrier is constructed whose inode would serve as the system V IPC key 
	 * @var string filename
	 */
	private $tmpfile;
	
	/**
	 * The semaphore and shared memory segment key
	 * @var int key
	 */
	private $key;
	
	/**
	 * How many processes would wait on this barrier 
	 * @var int number of parties
	 */
	private $parties;
	
	
	/**
	 * Once constructed, a barrier stores information into a shared memory segment.
	 * @var int size
	 */
	private $shmSegmentSize = 8192;
	

	/**
	 * Posix signal used to notify once the barrier gets tripped
	 * @var integer constant
	 */
	private $signal = SIGUSR2;
	

	/**
	 * When a process reached the barrier by invoking await() its pid get stored into a shared memory segment
	 * whose size is specified as second argument. The size must be large enough to store
	 * an integer, and a list of process ids (at least the one participating)
	 * @param integer $parties the number of processes partipating in the synchonization process
	 * @param integer $size a power of two size. 
	 */
	public function __construct($parties, $segmentSize = NULL){
		$this->parties = $parties;
		$this->shmSegmentSize = $segmentSize === NULL ? $this->shmSegmentSize : $segmentSize;
		$this->generateShmkey();
		$this->attach();
		$this->initBarrier();
	}

	private function initBarrier(){
		sem_acquire($this->semaphore);
		shm_put_var($this->shm, 0x1, $this->parties);
		shm_put_var($this->shm, 0x2, []);
		shm_put_var($this->shm, 0x3, 0);
		sem_release($this->semaphore);
	}
	
	/**
	 * Sets the signal that would be sent to wake up processes waiting for the barrier to get tripped
	 * @param int $signal
	 */
	public function setSignal($signal): void {
		$this->signal = $signal;
		pcntl_signal($this->signal, function($signo){});
	}
	
	/**
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\Synchronization.BarrierInterface::getNumberWaiting()
	 */
	public function getNumberWaiting(): int {
		sem_acquire($this->semaphore);
		$waiting = $this->parties - (int)shm_get_var($this->shm, 0x1);
		sem_release($this->semaphore);
		return (int)$waiting;
	}
	
	
	/**
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\Synchronization.BarrierInterface::getParties()
	 */
	public function getParties(): int {
		return $this->parties;
	}

    /**
     * Reset this barrier to keep using await
     * @throws \Exception if the barrier cannot be reset
     */
	public function reset(): void {
		if($this->getNumberWaiting() !== 0){
			throw new \Exception("Cannot reset barrier while parties are waiting");
		}
		if($this->shm === null || $this->semaphore === null){
		    throw new \RuntimeException("Cannot reset barrier. Already destroyed");
        }
		$this->initBarrier();	
	}
	
	/**
	 * (non-PHPdoc)
	 * @see \LightProcessExecutor\Synchronization\BarrierInterface::await()
	 */
	public function await(int $timeout = 0): void {
		if($this->isBroken()){
			throw new BrokenBarrierException("Barrier is broken. Cannot reach until reset.");
		}
		sem_acquire($this->semaphore);
		// A new process reached the barrier, decrement counter
		$count = shm_get_var($this->shm, 0x1);
		shm_put_var($this->shm, 0x1, --$count);
		$parties = shm_get_var($this->shm, 0x2);
		if($count === 0){
			// Barrier tripped
			sem_release($this->semaphore);
			$this->notifyAll($parties);
			shm_detach($this->shm);
		}
		else {
			// Somes processes have not yet terminated, make the current process sleep until it gets notified
			$parties[] =  posix_getpid();
			shm_put_var($this->shm, 0x2, $parties);
			sem_release($this->semaphore);
			$siginfo = [];
			if($timeout !== 0){
				$ret = pcntl_sigtimedwait(array($this->signal), $siginfo, $timeout);
			}
			else {
				$ret = pcntl_sigwaitinfo( array($this->signal), $siginfo);
			}
			if($ret === -1){
				// Mark the shared memory segment broken variable as true and notify all processes
				// Re-read the processes that have reached the barrier
				sem_acquire($this->semaphore);
				shm_put_var($this->shm, 0x3, 1);
				$parties = shm_get_var($this->shm, 0x2);
				sem_release($this->semaphore);
				$this->notifyAll($parties);
				$err = pcntl_get_last_error();
				if($err === PCNTL_EINTR){
					throw new InterruptedException(sprintf("Interrupted system call %s", 'pcntl_sigtimedwait'));
				}
				throw new TimeoutException($timeout);
			}
			else {
				// The process might exit the blocking call because one process has raised an exception (barrier is broken), check the shared memomy variable
                if($this->isBroken()){
                    fprintf(STDERR, "BrokenBarrier\n");
					throw new BrokenBarrierException("Process woke up due to broken barrier");
				}
			}
		}
	}

	/**
	 * Exclusive read on a shared memory variable indicating whether the barrier is broken
	 * @return boolean true or false
	 */
	private function isBroken(): bool {
	    fprintf(STDOUT, "isBroken() called\n");
		if(false === sem_acquire($this->semaphore)){
            // FIXME: handle this edge case when first process after await() closes the resources due to destruct
        }
		$broken = (bool) shm_get_var($this->shm, 0x3);
		sem_release($this->semaphore);
		return $broken;
	}
	
	/**
	 * Notifies all the processes in parties array with a signal. Signal sent is configured using setSignal()
	 * @param array $parties
	 */
	private function notifyAll(array $parties){
		foreach($parties as $pid){
		    fprintf(STDOUT, "Notify parties\n");
			posix_kill($pid, $this->signal);
		}
	}
	
	private function generateShmkey(): void {
			$this->tmpfile = tempnam('/tmp', 'barrier');
			$this->key = fileinode($this->tmpfile);
	}
	
	private function attach(): void {
		$this->shm = shm_attach($this->key, $this->shmSegmentSize);
		if(!$this->shm){
			throw new \RuntimeException("Cannot create shared memory segment {$this->key}");
		}
		if(false === ($this->semaphore = sem_get($this->key))){
		throw new \RuntimeException("Cannot create semaphore with key {$this->key} for shared memory segment {$this->key}");
		}
	} 
	
	
	private function detach(): void {
		if(false === shm_detach($this->shm)){
		throw new \RuntimeException("Cannot detach shared memory segment {$this->key}");
		}
		sem_remove($this->semaphore);
	}
	
	/**
	 * Upon reconstructing the object, recreate the shared memory segment resource and the semaphore resource from
	 * the key
	 */
	public function __wakeup(){
		$this->attach();
	}
	
	/**
	 * On serialization, detach the shared memory segment, and remove the semaphore resource
	 * @return array of string that maps to internal properties to be serialized
	 */
	public function __sleep(){
		$this->detach();
		return array('parties', 'key', 'tmpfile', 'shmSegmentSize', 'signal');
	}


	public function __destruct(){
	    fprintf(STDOUT, "Destruct call\n");
	    if(is_resource($this->shm)){
            shm_remove($this->shm);
            $this->shm = null;
        }
	    if(is_resource($this->semaphore)){
	        // If barrier is serialized, the last process that destroys the last hold reference to this barrier will emit warning
            // as the semaphore would have been closed already
            @sem_remove($this->semaphore);
            $this->semaphore = null;
        }
	    if(file_exists($this->tmpfile)){
            unlink($this->tmpfile);
        }
	}	
}
