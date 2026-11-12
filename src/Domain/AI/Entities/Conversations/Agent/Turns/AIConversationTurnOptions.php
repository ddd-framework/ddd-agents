<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

/**
 * How one turn invocation runs its side work — a plain mutable runtime object set by the entry
 * point that starts the turn, never persisted. The CLI and eval path works SYNC (no Messenger consumer in the console),
 * the HTTP and worker path dispatches its side work async; a CLI `--no-side-work` latency benchmark switches it off.
 */
class AIConversationTurnOptions
{
    /** Whether a new message's recall embedding is dispatched to the worker (true) or computed inline (false). */
    public bool $embedMessagesAsync = true;

    /** Whether the per-message recall embedding runs at all — false only on a CLI `--no-side-work` benchmark. */
    public bool $embedMessagesEnabled = true;
}
