<?php
namespace Loop\Core\Synchronization;

use Loop\Core\Synchronization\Exception\BrokenBarrierException;
use Loop\Core\Synchronization\Exception\InterruptedException;
use Loop\Core\Synchronization\Exception\TimeoutException;

/**
 * A simple barrier synchronization mechanism that uses a shared memory counter
 * to synchronize processes and posix blocking sigwait mechanism to get notify when the barrier gets tripped.
 * The last process to reach the barrier notifies all the others.
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

	private $enableLogger = false;

	private $barrierReached = false;


    public function setLoggingEnabled(bool $enabled): PosixSignalBarrier {
        $this->enableLogger = $enabled;
        return $this;
    }

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

    private function log(string $format, ...$args){
        if(!$this->enableLogger){
            return;
        }
        $log = sprintf($format, ...$args);
        fprintf(STDOUT, "Pid: %5d - %-100s\n", posix_getpid(), $log);
    }

	private function initBarrier($reset = false){
	    $this->barrierReached = false;
		sem_acquire($this->semaphore);
		if($reset){
            shm_remove_var($this->shm, 0x1);
            shm_remove_var($this->shm, 0x2);
            shm_remove_var($this->shm, 0x3);
            shm_remove_var($this->shm, 0x4);
        }
		// Number of process runners
		shm_put_var($this->shm, 0x1, $this->parties);
		// Pid of processes waiting
		shm_put_var($this->shm, 0x2, []);
		// Flag to determine, whether barrier is broken (shared accross process)
		shm_put_var($this->shm, 0x3, 0);
		// Counter holding the number signaled process
        shm_put_var($this->shm, 0x4, 0);
		sem_release($this->semaphore);
	}

	/**
	 * Sets the signal that would be sent to wake up processes waiting for the barrier to get tripped
	 * @param int $signal
	 */
	public function setSignal($signal): void {
		$this->signal = $signal;
		pcntl_signal($this->signal, function($signo){
		    $this->barrierReached = true;
        });
	}
	
	/**
	 * (non-PHPdoc)
	 * @see LightProcessExecutor\Synchronization.BarrierInterface::getNumberWaiting()
	 */
	public function getNumberWaiting(): int {
		sem_acquire($this->semaphore);
		$waiting = (int) shm_get_var($this->shm, 0x1);
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
		$this->initBarrier(true);
		$this->log('Barrier reset ok');
	}
	
	/**
	 * (non-PHPdoc)
	 * @see \LightProcessExecutor\Synchronization\BarrierInterface::await()
	 */
	public function await(int $timeout = 0): void {
	    if($this->barrierReached){
	       $waitCond = shm_has_var($this->shm, 0x1);
	       $this->log('Waiting for barrier to be reset');
	       $guard = 5; $i = 0;
	       while(!$waitCond){
	           usleep(100000);
	           if($i++ === $guard){
	               $this->log('Bug we have avoided infinite loop');
	               break;
               }
           }
           if(!$waitCond){
                $this->log('Cant await after already reached or broken barrier. Reset it to re-use');
                return;
           }
           $this->log('Ok, barrier reset');
           $this->barrierReached = false;
        }
	    $this->log('await() after barrier');
		if($this->isBroken()){
			throw new BrokenBarrierException("Barrier is broken. Cannot reach until reset.");
		}
		sem_acquire($this->semaphore);
		$this->log("Semaphore acquired");
		// A new process reached the barrier, decrement counter
		$count = shm_get_var($this->shm, 0x1);
		shm_put_var($this->shm, 0x1, --$count);
		$parties = shm_get_var($this->shm, 0x2);
		if($count === 0){
			// Barrier tripped
            $this->log('All process have joined the barrier');
            $this->notifyAll($parties);
			$this->barrierReached = true;
            sem_release($this->semaphore);
		}
		else {
			// Somes processes have not yet terminated, make the current process sleep until it gets notified
            $this->log('Waiting at barrier with count = %d', $count);
			$parties[] =  posix_getpid();
			shm_put_var($this->shm, 0x2, $parties);
			sem_release($this->semaphore);
			$siginfo = [];
            $this->log('Waiting signal');
			if($timeout !== 0){
				$ret = pcntl_sigtimedwait(array($this->signal), $siginfo, $timeout);
			}
			else {
				$ret = pcntl_sigwaitinfo( array($this->signal), $siginfo);
			}
			if($ret === -1){
                $err = pcntl_get_last_error();
                $this->log('fails to wait for barrier signal with pcntl_error %d', $err);
				// Mark the shared memory segment broken variable as true and notify all processes
				// Re-read the processes that have reached the barrier
				sem_acquire($this->semaphore);
				shm_put_var($this->shm, 0x3, 1);
				$parties = shm_get_var($this->shm, 0x2);
				sem_release($this->semaphore);
                if(false === $parties){
                    $this->log('Buggy, barrier has broken or is reached already, did you forget to reset it ?...');
                    $this->updateUnblockCounter();
                    throw new BrokenBarrierException('Something went wrong');
                }
                $this->notifyAll($parties);
                $this->updateUnblockCounter();
                if($err === PCNTL_EINTR){
					throw new InterruptedException(sprintf("Interrupted system call %s", 'pcntl_sigtimedwait'));
				}
				throw new TimeoutException($timeout);
			}
			else {
			    // We have terminated waiting for the barrier signal (means either reached or broken)
                // This process stills needs to access the shared memory to check if the barrier is broken
			    $this->log('Got unblocked barrier tripped');
			    $this->barrierReached = true;
				// The process might exit the blocking call because one process has raised an exception (barrier is broken), check the shared memomy variable
                if($this->isBroken()){
                    $this->log('Unblocked due to broken barrier');
                    $this->updateUnblockCounter();
					throw new BrokenBarrierException("Process woke up due to broken barrier");
				}
                $this->updateUnblockCounter();
			}
		}
		// Reset the barrier if await count
	}

	private function updateUnblockCounter(): void {
	    $this->log('updateUnblockCounter()');
        sem_acquire($this->semaphore);
        $unblocked = shm_get_var($this->shm, 0x4);
        shm_put_var($this->shm, 0x4, ++$unblocked);
        $waiting = shm_get_var($this->shm, 0x1);
        sem_release($this->semaphore);
        $this->log('Update unblocked by signal counter to %d', $unblocked);
        $this->log('Number of parties still waiting %d', $waiting);
        $this->autoReset();
    }

	private function autoReset(): void {
        $this->log('autoReset()');
        sem_acquire($this->semaphore);
        $nbUnblockedProcessed = shm_get_var($this->shm, 0x4);
        sem_release($this->semaphore);
        // Unblocked process is the number of parties minus 1
        if($nbUnblockedProcessed === ($this->parties - 1)){
            $this->log("Last process quitting barrier, auto reset barrier");
            $this->reset();
        }
    }

	/**
	 * Exclusive read on a shared memory variable indicating whether the barrier is broken
	 * @return boolean true or false
	 */
	private function isBroken(): bool {
		if(false === sem_acquire($this->semaphore)){
            // FIXME: handle this edge case when first process after await() closes the resources due to destruct
            $this->log('Bug, cant acquire semaphore to check if broken');
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
		return array('parties', 'key', 'tmpfile', 'shmSegmentSize', 'signal', 'enableLogger', 'barrierReached');
	}


	public function __destruct(){
	    $this->log('Destroying barrier');
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
