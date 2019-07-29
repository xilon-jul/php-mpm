<?php
namespace Loop\Util;

trait Logger {

    private $enableLogger = true;

    public function log(string $context, string $format, ...$args){
        if(!$this->enableLogger){
            return;
        }
        $log = sprintf($format, ...$args);
        $time = date('H:i:s');
        fprintf(STDOUT, "[%-'-10s %-5d %8s] => %-100s\n", $context, posix_getpid(), $time, $log);
    }

}
