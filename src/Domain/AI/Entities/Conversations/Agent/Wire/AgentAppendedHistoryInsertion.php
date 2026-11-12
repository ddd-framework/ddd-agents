<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

/**
 * What one model call of an append-only conversation appended to the history: the tools that joined
 * (the `additional_tools` item) and the instructions of the skills that loaded / the situation that changed (a
 * `developer` message). Rendered on the wire at the position the call appended it — right after the newest row the call
 * saw ({@see \DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot::$appendedAfterMessageIndex}) — on this call
 * and on every later call of the epoch, byte for byte. Pure.
 */
class AgentAppendedHistoryInsertion
{
    /** @var string The developer message text; '' when only tools joined. */
    public string $developerText = '';

    /** @var array<int, array<string, mixed>> The Chat-Completions schemas of the tools that joined; [] when none. */
    public array $chatCompletionsToolSchemas = [];

    /**
     * @var string[] The state notes (`[SIGNAL:<kind>] …`) delivered at this position — one user entry after
     *      the developer message; [] when none (then the entries are byte-identical to those without state notes).
     */
    public array $userTexts = [];

    public function isEmpty(): bool
    {
        return $this->developerText === '' && $this->chatCompletionsToolSchemas === [] && $this->userTexts === [];
    }

    /**
     * The wire entries in the Chat-form message list: the `additional_tools` item first (it cannot carry a cache
     * breakpoint), then the developer message (the wire marker may land on it), then the state notes as one user entry.
     * The Responses converter turns them into the `additional_tools` input item, a `developer` message and
     * a `user` message.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toWireEntries(): array
    {
        $wireEntries = [];
        if ($this->chatCompletionsToolSchemas !== []) {
            $wireEntries[] = ['type' => 'additional_tools', 'role' => 'developer', 'tools' => $this->chatCompletionsToolSchemas];
        }
        if ($this->developerText !== '') {
            $wireEntries[] = ['role' => 'developer', 'content' => $this->developerText];
        }
        if ($this->userTexts !== []) {
            $wireEntries[] = ['role' => 'user', 'content' => implode("\n\n", $this->userTexts)];
        }
        return $wireEntries;
    }
}
