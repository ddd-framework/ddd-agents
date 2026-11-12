<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationEarlierRunReport;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The reports earlier runs of the asking run's schedule wrote for the owner, newest first — or the note why there are
 * none (the first report of the schedule, or no scheduled run).
 */
class PreviousTaskRunReportsMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var AIConversationEarlierRunReport[] The earlier reports, newest first. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $reports = [];

    /** @var string|null Why no report is listed. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    public function toLlmMarkdown(): string
    {
        if ($this->reports === []) {
            return trim((string)$this->note);
        }
        return implode("\n\n---\n\n", array_map(
            static fn (AIConversationEarlierRunReport $earlierRunReport): string => $earlierRunReport->toLlmMarkdown(),
            $this->reports
        ));
    }
}
