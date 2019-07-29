<?php
namespace Loop\Core;

use Loop\Util\Logger;

class ProcessInfo {

    use Logger;

    const PROCESS_ST_RUNNING = 0;
    const PROCESS_ST_STOPPED = 1;
    const PROCESS_ST_EXITED = 2;

    const AVAIL_READY = 0;
    const AVAIL_WORKING = 1;


    private static $statusMapping = [
        self::PROCESS_ST_RUNNING => 'Running',
        self::PROCESS_ST_STOPPED => 'Stopped',
        self::PROCESS_ST_EXITED => 'Exited'
    ];

    private $availibility = self::AVAIL_READY;

    private $parent = null;
    private $children = [];
    private $labels = [];
    private $isRootOfHierarchy = false;
    private $pid;
    private $status;
    private $statusReason;
    private $startTime;
    private $idleTime;
    private $lastStatusModifiedTime;
    private $rusage = [];

    /**
     * @var $pipes Pipe[]
     */
    private $pipes = [];

    public function __construct(int $pid)
    {
        $this->pid = $pid;
        $this->status = self::PROCESS_ST_RUNNING;
        $this->startTime = $this->lastStatusModifiedTime = time();
    }

    public function setStatus(int $status, string $statusReason = null): ProcessInfo {
        $this->statusReason = $statusReason ?? "";
        $time = time();
        if($this->status === self::PROCESS_ST_STOPPED && $status === self::PROCESS_ST_RUNNING){
            // Process was stopped until this call set the status to running
            $this->idleTime += $time - $this->lastStatusModifiedTime;
        }
        $this->lastStatusModifiedTime = $time;
        $this->status = $status;
        return $this;
    }

    public function isAvailable(){
        return $this->availibility === self::AVAIL_READY;
    }

    public function setAvailable(bool $available): ProcessInfo {
        $this->availibility = ($available ? self::AVAIL_READY : self::AVAIL_WORKING);
        return $this;
    }

    public function setResourceUsage(array $resourceUsage){
        $this->rusage = $resourceUsage;
        return $this;
    }


    public function getPidBoundToFd($fd): ?int {
        /**
         * @var $targetPipe Pipe
         */
        $targetPipe = array_shift(array_filter($this->pipes, function(Pipe $pipe) use($fd) {
            return $pipe->getFd() === $fd;
        }));
        return $targetPipe ? $targetPipe->getPid() : null;
    }

    /**
     * @param bool $isRootOfHierarchy
     */
    public function setIsRootOfHierarchy(bool $isRootOfHierarchy): ProcessInfo
    {
        $this->isRootOfHierarchy = $isRootOfHierarchy;
        return $this;
    }

    public function addChild(ProcessInfo $child){
        $this->log('pinfo', 'Add child %-5d', $child->getPid());
        $this->children[$child->getPid()] = $child;
        return $this;
    }

    public function getProcessInfo(int $pid): ?ProcessInfo {
        if($this->getPid() === $pid){
            return $this;
        }
        return $this->children[$pid];
    }

    public function getProcessInfoByLabel(string $label): ?ProcessInfo {
        return array_shift(array_filter($this->getChildren(), function(ProcessInfo $child) use($label) {
           return $child->hasLabel($label);
        }));
    }

    public function freePipe(int $pid): bool {
        /**
         * @var $p Pipe
         */
        $pipesToFilter = $this->getPipes();
        if($pid > 0){
            $pipesToFilter = array_filter($this->pipes, function(Pipe $p) use($pid) {
                return $p->getPid() === $pid;
            });
        }
        if(count($pipesToFilter) === 0){
            return false;
        }
        $this->log('pinfo', 'Free pipes to pids: (%s)', implode(',', array_map(function($p){ return $p->getPid(); }, $pipesToFilter)));
        array_walk($pipesToFilter, function(Pipe $p){
            $p->free();
        });

        $this->pipes = array_udiff($this->pipes, $pipesToFilter, function(Pipe $a, Pipe $b){
            if($a->getFd() === $b->getFd() && $a->getPid() === $b->getPid()){
                return 0;
            }
            return -1;
        });
        $this->parent = null;
        return true;
    }

    public function free(){
        $this->freePipe(-1);
    }

    /**
     * Simply unsets the children array, does not free any associated pipes
     */
    public function resetChildren(){
        $this->children = [];
        $this->pipes = [];
    }

    public function removeChildren(): ProcessInfo {
        foreach($this->children as $pid => $v){
            $this->removeChild($pid);
        }
        return $this;
    }

    public function removeChild(int $pid): ProcessInfo {
        $this->log('pinfo', 'Remove child %-5d', $pid);
        $this->freePipe($pid);
        unset($this->children[$pid]);
        return $this;
    }

    public function hasChildren(): bool {
        return count($this->children) > 0;
    }

    public function getChildren(): array {
        return $this->children;
    }

    public function setParentProcessInfo(ProcessInfo $processInfo): ProcessInfo {
        $this->parent = $processInfo;
        return $this;
    }

    public function getParentProcessInfo(): ?ProcessInfo {
        return $this->parent;
    }

    public function hasParent(): bool {
        return $this->parent !== null;
    }

    public function hasPipe(int $pid): bool {
        return count(array_filter($this->pipes, function(Pipe $p) use($pid) { return $p->getPid() === $pid; })) === 1;
    }

    public function countPipes(): int {
        return count($this->pipes);
    }

    public function setLabels(?string... $labels): ProcessInfo {
        if(!$labels){
            return $this;
        }
        $this->labels = $labels;
        return $this;
    }

    public function getLabels(): array {
        return $this->labels;
    }

    public function hasLabel(string $label){
        return in_array($label, $this->getLabels(), true) !== false;
    }

    public function addPipe(Pipe $pipe): ProcessInfo {
        if(posix_getppid() !== $pipe->getPid() && !$this->getProcessInfo($pipe->getPid())){
            throw new \Exception("A pipe must target a parent process or a child process");
        }
        $this->pipes[] = $pipe;
        return $this;
    }

    public function getPipes(): array {
        return $this->pipes;
    }

    /**
     * @return bool
     */
    public function isRootOfHierarchy(): bool
    {
        return $this->isRootOfHierarchy;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    public function getUptime(): int {
        return time() - $this->startTime;
    }

    public function getIdleTime(): int {
        return $this->idleTime;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function isExited(&$info = null): bool {
        if($this->status !== self::PROCESS_ST_EXITED) return false;
        if($info !== null){
            $info = $this->statusReason;
        }
        return true;
    }

    /**
     * @return mixed
     */
    public function getStatusReason()
    {
        return $this->statusReason;
    }

    public function isReady(): bool {
        return $this->status === self::PROCESS_ST_RUNNING;
    }

    public function __clone(){
        $this->log('pinfo', 'Cloning process info with pid %-5d', $this->pid);
        $this->pipes = [];
        $this->children = [];
    }

    public function __toString(): string
    {
        return sprintf("Pid: %d, Labels: (%s), Status: %s, Uptime (sec): %s, Status string = %s", $this->pid, implode(',', $this->getLabels()), self::$statusMapping[$this->status], $this->getUptime(), $this->getStatusReason());
    }
}
