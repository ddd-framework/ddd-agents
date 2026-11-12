<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

/**
 * A tool response that carries a PLATFORM NOTE for the model next to its result: how to read the result, what to do
 * next, why a page failed. The note reaches the model on the wire right after the
 * result ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$platformNoteForModel}) but is kept OUT of the
 * stored result: the injection screening reads only the result, and our own instructions to the model scored
 * 0.65–0.96 as "an instruction aimed at an AI" and got whole page reads removed.
 */
interface MCPToolResponsePlatformNoteProvider
{
    /** The note for the model, or null when there is none. */
    public function buildPlatformNoteForModel(): ?string;
}
