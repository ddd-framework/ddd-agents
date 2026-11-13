<?php
// placeholder {{NAMESPACE}} sample: AgentsReference\Presentation\Mcp\Demo
// placeholder {{FEATURE}} sample: DemoNotes

declare(strict_types=1);

namespace {{NAMESPACE}}\Dtos;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of both tools. A tool returns a RestResponseDto, never a bare entity. Because it implements
 * LlmMarkdownRenderable, the model reads toLlmMarkdown() unless a caller asks for outputFormat=structured; the
 * structured shape stays what an HTTP client asking for it gets.
 */
class {{FEATURE}}McpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string[] The items of this window, oldest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $items = [];

    /** @var int How many items exist in total */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $totalCount = 0;

    /** @var int The window size the call asked for; 0 = no limit */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public int $windowSize = 0;

    /**
     * @var bool|null False when a write was refused before anything was stored: the loop then treats the same call
     *      after the owner's answer as a retry, never as an identical repeat. Loop control — nothing for the model.
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?bool $applied = null;

    /**
     * The model's reading: the window first (which rows of how many), then one line per item; an empty list reads as
     * an answer, never as an error.
     *
     * Example output:
     *   Rows 1–2 of 2.
     *   1. Order coffee beans on Friday
     *   2. Call the supplier
     */
    public function toLlmMarkdown(): string
    {
        if ($this->items === []) {
            return 'No items yet.';
        }
        $itemLines = ['Rows 1–' . count($this->items) . ' of ' . $this->totalCount . '.'];
        foreach (array_values($this->items) as $position => $itemText) {
            $itemLines[] = ($position + 1) . '. ' . $itemText;
        }
        return implode("\n", $itemLines);
    }
}
