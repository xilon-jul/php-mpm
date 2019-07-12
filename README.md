# How to test this development

This docker image is based on php 7.3.3 with debug-symbols enabled and core dump configured to log into /tmp directory. 
You can do the following to debug a core dump :
```bash

> docker-php-source extract
# And in gdb you can now source /usr/src/php/.gdbinit to have specific debug command for php binary
> gdb php <core_file>

````

```bash
docker run --privileged --ulimit core=10000000 -v $(pwd):/app -w /app -ti itengo/xilon:php7.3.3-fpm-debug bash
```

- TODO :
    * Coalesce message
    * Event for file change / directory change (via inotify)
