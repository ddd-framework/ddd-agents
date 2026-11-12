<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\Mandates\AgentMandates;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Response of `listMandates`: the account's mandates, newest first. Renders compactly for the agent by
 * delegating to {@see AgentMandates::toLlmMarkdown()}.
 */
class AgentMandatesMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var AgentMandates The account's mandates (all of them, or the view of one space), newest first. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentMandates $mandates;

    public function toLlmMarkdown(): string
    {
        return $this->mandates->toLlmMarkdown();
    }
}
