#!/bin/bash

version=7.3

wget -O - https://raw.githubusercontent.com/php/php-src/PHP-${version}/.gdbinit > 
echo "/tmp/core-%t-%p" > /proc/sys/kernel/core_pattern

