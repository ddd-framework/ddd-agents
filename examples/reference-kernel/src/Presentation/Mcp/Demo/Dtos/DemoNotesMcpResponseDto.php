<?php

declare(strict_types=1);

namespace AgentsReference\Presentation\Mcp\Demo\Dtos;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The demo owner's notes, oldest first. */
class DemoNotesMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string[] The notes, oldest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $notes = [];

    public function toLlmMarkdown(): string
    {
        if ($this->notes === []) {
            return 'No notes yet.';
        }
        $noteLines = [];
        foreach ($this->notes as $position => $noteText) {
            $noteLines[] = ($position + 1) . '. ' . $noteText;
        }
        return "Notes:\n" . implode("\n", $noteLines);
    }
}
