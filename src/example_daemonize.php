<?php

use Loop\Core\Loop;

require_once __DIR__.'/../vendor/autoload.php';

$loop = new \Loop\Core\Loop();
$loop->detach();
$loop->fork();
$loop->loop();



