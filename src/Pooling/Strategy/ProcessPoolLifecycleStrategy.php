<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-06
 * Time: 20:43
 */

namespace Loop\Pooling\Strategy;


use Loop\Pooling\ProcessPool;

interface ProcessPoolLifecycleStrategy
{
    function onPoolStart(ProcessPool $processPool): void;

    function onPreDispatch(ProcessPool $processPool): void;

    function onTaskPreSubmit(ProcessPool $processPool): void;

    function onTaskPostSubmit(ProcessPool $processPool): void;

    function onPostDispatch(ProcessPool $processPool): void;
}
