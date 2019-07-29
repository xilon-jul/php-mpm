<?php
namespace Loop\Util;

abstract class Logger {

    static $enableLogger = false;

    public static function enable(): void {
        self::$enableLogger = true;
    }


    public static function disable(): void {
        self::$enableLogger = false;
    }

    public static function log(string $context, string $format, ...$args){
        if(!self::$enableLogger){
            return;
        }
        $log = sprintf($format, ...$args);
        $time = date('H:i:s');
        fprintf(STDOUT, "[%-'-10s %-5d %8s] => %-100s\n", $context, posix_getpid(), $time, $log);
    }

}
