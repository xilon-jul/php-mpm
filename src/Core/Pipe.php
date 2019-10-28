<?php

namespace Loop\Core;



use Loop\Util\Logger;

/**
 * Class Pipe
 * A pipe is a container that aggregates a file descriptor with both read and write event (as defined in Event in libev) and it also contains a process identifier and a set of string labels.
 * We consider that we read from this process id and write  to this process id
 * @package Loop\Core
 */
class Pipe
{

    private $eread, $ewrite;
    private $fd;
    private $pid;
    private $labels = [];

    public function __construct(int $pid, $fd, \Event &$eread, \Event &$ewrite, ?string... $labels)
    {
        Logger::log('pipe', 'Create pipe to pid %-5d to fd %d', $pid, $fd);
        $this->pid = $pid;
        $this->fd = $fd;
        $this->eread = $eread;
        $this->ewrite = $ewrite;
        $this->labels = $labels;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @return mixed
     */
    public function getFd()
    {
        return $this->fd;
    }

    /**
     * @return \Event
     */
    public function & getEread(): \Event
    {
        return $this->eread;
    }

    /**
     * @return \Event
     */
    public function & getEwrite(): \Event
    {
        return $this->ewrite;
    }

    /**
     * @return string[]
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    public function free(){
        Logger::log('pipe', 'Free pipe to pid %-5d with fd %d', $this->pid, $this->fd);
        $this->eread->free();
        $this->ewrite->free();
        $this->eread = null;
        $this->ewrite = null;
        socket_shutdown($this->fd);
        if(false === @socket_close($this->fd)){
            // fprintf(STDERR, "Could not close socket : %s", socket_last_error($this->fd));
        }
        $this->fd = null;
    }
}
