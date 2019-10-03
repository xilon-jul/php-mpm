<?php
namespace Loop\Util;

abstract class Logger {

    static $enableLogger = false;
    static $logContextList = [];

    public static function enable(): void {
        self::$enableLogger = true;
    }

    public static function enableContexts(string ...$contexts): void {
        foreach($contexts as $ctx){
            self::$logContextList[$ctx] = true;
        }
    }

    private static function shouldLog(string $context): bool {
        return self::$enableLogger === true && (
            isset(self::$logContextList[$context]) || count(self::$logContextList) === 0
        );
    }

    public static function disable(): void {
        self::$enableLogger = false;
    }

    public static function log(string $context, string $format, ...$args){
        if(false === self::shouldLog($context)){
            return;
        }
        $log = sprintf($format, ...$args);
        $time = date('H:i:s');
        fprintf(STDOUT, "[%-'-10s %-5d %8s] => %-100s\n", $context, posix_getpid(), $time, $log);
    }

}
