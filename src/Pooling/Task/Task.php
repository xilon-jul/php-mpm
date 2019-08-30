<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-08-06
 * Time: 00:08
 */

namespace Loop\Pooling\Task;


interface Task
{
    function isPeriodic(): bool;
    function periodicity(): ?string;
    function name(): string;
    function execute(): ?TaskResult;
}
