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
    const LOOP_ACTION_INOTIFY_EVENT = 'internal_action_inotify_event';
    const LOOP_ACTION_EIO_EVENT = 'internal_action_eio_event';
    const LOOP_ACTION_MESSAGE_RECEIVED = 'internal_action_message_received';
    const LOOP_ACTION_PROCESS_TERMINATED = 'internal_action_process_terminated';
    const LOOP_ACTION_PROCESS_CHILD_TERMINATED = 'internal_action_process_child_terminated';
    const LOOP_ACTION_PROCESS_ORPHANED = 'internal_action_process_orphaned';
    const LOOP_ACTION_PROCESS_CHANNEL_CLOSED = 'internal_action_process_channel_closed';


    private $trigger;
    private $persistent, $immediate;
    private $surviveAcrossForkCallable;
    private $callable;
    private $runtimeArgs = [];


    /**
     * LoopAction constructor.
     * @param string $trigger the trigger name one of
     * @see LoopAction::LOOP_ACTION_BEFORE_DISPATCH
     * @see LoopAction::LOOP_ACTION_PROCESS_STOPPED
     * @see LoopAction::LOOP_ACTION_PROCESS_CONTINUED
     * @see LoopAction::LOOP_ACTION_MESSAGE_RECEIVED
     * @see LoopAction::LOOP_ACTION_PROCESS_TERMINATED
     * @see LoopAction::LOOP_ACTION_PROCESS_CHILD_TERMINATED
     * @see LoopAction::LOOP_ACTION_PROCESS_ORPHANED
     * @see LoopAction::LOOP_ACTION_PROCESS_CHANNEL_CLOSED
     * @param bool $persistent should this action put back into dispatch queue on next loop run
     * @param bool $immediate
     * @param $callable
     * @param null $surviveAcrossForkCallable
     */
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

    /**
     * If true, this action will survive across fork. otherwise, it will be dropped
     * for any children
     * @param Loop $loopContext
     * @return bool
     */
    public function survivesAcrossFork(Loop $loopContext): bool
    {
        if($this->surviveAcrossForkCallable === null){
            return true;
        }
        return call_user_func($this->surviveAcrossForkCallable, $loopContext);
    }

    /**
     * A persistent action is an action that when run is put back into the action queue to
     * be run again on next dispatch loop. Dont forget that an action is run only if it has to be triggered.
     * @return bool true if it should persist
     */
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function getRuntimeArgs(): array
    {
        return $this->runtimeArgs;
    }

    /**
     * Immediate actions ask the main loop to stop processing any events it may have except
     * the current one the loop is executing. It allows to ensure this action will run in less time
     * that usual.
     * @return bool
     */
    public function isImmediate(): bool
    {
        return $this->immediate;
    }

    public function removeRuntimeArgs(): void {
        $this->runtimeArgs = [];
    }

    public function setRuntimeArgs(...$args): void
    {
        array_push($this->runtimeArgs, ...$args);
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function invoke(...$args): void
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
