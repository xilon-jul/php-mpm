<?php
/**
 * Created by PhpStorm.
 * User: jpons
 * Date: 2019-03-28
 * Time: 21:31
 */

namespace Loop\Core\Action;


use Loop\Core\Loop;

final class LoopAction
{

    const LOOP_ACTION_BEFORE_DISPATCH = 'internal_action_before_dispatch';
    const LOOP_ACTION_PROCESS_STOPPED = 'internal_action_process_stopped';
    const LOOP_ACTION_PROCESS_CONTINUED = 'internal_action_process_continued';
    const LOOP_ACTION_MESSAGE_RECEIVED = 'internal_action_message_received';
    const LOOP_ACTION_PROCESS_TERMINATED = 'internal_action_process_terminated';
    const LOOP_ACTION_PROCESS_CHILD_TERMINATED = 'internal_action_process_child_terminated';
    const LOOP_ACTION_PROCESS_FOREIGN_CHILD_TERMINATED = 'internal_action_process_foreign_child_terminated';
    const LOOP_ACTION_PROCESS_ORPHANED = 'internal_action_process_orphaned';
    const LOOP_ACTION_PROCESS_CHANNEL_CLOSED = 'internal_action_process_channel_closed';


    private $trigger;
    private $persistent, $immediate;
    private $surviveAcrossForkCallable;
    private $callable;
    private $runtimeArgs = [];


    public function __construct(
        string $trigger,
        bool $persistent,
        bool $immediate,
        $callable,
        $surviveAcrossForkCallable = null)
    {
        $this->trigger = $trigger;
        $this->persistent = $persistent;
        $this->surviveAcrossForkCallable = $surviveAcrossForkCallable;
        $this->callable = $callable;
        $this->immediate = $immediate;
    }


    public function survivesAcrossFork(Loop $loopContext): bool
    {
        if($this->surviveAcrossForkCallable === null){
            return true;
        }
        return call_user_func($this->surviveAcrossForkCallable, $loopContext);
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function getRuntimeArgs(): array
    {
        return $this->runtimeArgs;
    }

    public function isImmediate(): bool
    {
        return $this->immediate;
    }


    function setRuntimeArgs(...$args): void
    {
        $this->runtimeArgs = $args;
    }

    function trigger(): string
    {
        return $this->trigger;
    }

    function invoke(...$args): void
    {
        call_user_func($this->callable, ...$args);
    }

    /**
     * The first action to get executed is the one with the lowest priority
     * @return int the priority
     */
    public final function getPriority(): int {
        switch($this->trigger){
            case LoopAction::LOOP_ACTION_PROCESS_TERMINATED:
                return 0;
            case LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED:
                return -1;
            case LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED:
                return 1;
            case LoopAction::LOOP_ACTION_PROCESS_ORPHANED:
                return 2;
            case LoopAction::LOOP_ACTION_MESSAGE_RECEIVED:
                return -2;
            case LoopAction::LOOP_ACTION_BEFORE_DISPATCH:
                return 4;
            default:
                return 1000;
        }
    }
}
