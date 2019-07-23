<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-03-27
 * Time: 17:02
 */

namespace Loop\Core;


use http\Exception\RuntimeException;

class Pipe
{
    private $eread, $ewrite;
    private $fd;
    private $pid;
    private $labels = [];

    public function __construct(int $pid, $fd, \Event &$eread, \Event &$ewrite, ?string... $labels)
    {
        if(is_int($fd)){
            throw new RuntimeException("Expected resource as fd");
        }
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
        $this->eread->del();
        $this->ewrite->del();

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
