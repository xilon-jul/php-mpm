<?php
namespace Loop\Core\Synchronization;

/**
 * BarrierInterface that allows multiple processes to synchronize.
 * To synchonize processes, the barrier implementation specifies the number
 * of parties participating in the synchronization process. Each process is then expected
 * to invoke the await() method when it wants to synchronize. 
 *
 * When all the processes have reached the barrier, the barrier is tripped, and it might
 * get reset so that it can be re-used. 
 */

interface BarrierInterface {

	/**
	 * Returns the number of parties currently waiting at the barrier.
	 */
	public function getNumberWaiting(): int;
	
	/**
	 * Retrieves the number of parties that need to be synchronized
	 * @return integer number of parties
	 */
	public function getParties(): int;
	
	/**
	 * Reset the barrier so that it can be re-used.
	 * @throws \Exception if the barrier is being used
	 * @return void
	 */
	public function reset(): void;
	
	
	/**
	 * Waits until all parties have invoked await on this barrier.
	 * @throws
	 * <pre> 
	 * 	InterruptedException if one process was interrupted during its wait<br>
	 *  TimeoutException if the wait has reached the specified timeout or if the timeout specified is invalid
	 *  BrokenBarrierException in all the other processes except the one that was interrupted or timed out
	 * </pre>
	 * @return void  
	 */
	public function await(int $timeout = 0) : void ;
}
