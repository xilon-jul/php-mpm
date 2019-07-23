<?php

use Loop\Core\Loop;

require_once __DIR__.'/../vendor/autoload.php';


/*/

This is the process tree :

            P0
           /
          P1


This example demonstrates how one process can monitor file changes using inotify.
P0 watches for /tmp/file1 and P1 watches for /tmp/file2
/*/


if(! ($f1 = fopen('/tmp/file1', 'w+'))){
    throw new \RuntimeException("Cannot create /tmp/file1");
}
if(! ($f2 = fopen('/tmp/file2', 'w+'))){
    throw new \RuntimeException("Cannot create /tmp/file2");
}

$loop = new \Loop\Core\Loop();
$loop->setLoggingEnabled(false);

$cb = function(Loop $loop, $arg) {
    echo posix_getpid(). ' with pathname ' . $arg[0]['pathname'].PHP_EOL;
};

$loop->addFileWatch('/tmp/file1', $cb);

$loop->fork(function(Loop $loop) use($cb) {
    $loop->addFileWatch('/tmp/file2', $cb);
});


$loop->loop();

fprintf(STDOUT, 'Bye bye %s', PHP_EOL);

fclose($f1);
fclose($f2);


