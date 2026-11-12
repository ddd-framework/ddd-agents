<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The decision statistics of one site over a window, as
 * {@see \DDD\Domain\AI\Services\Decisions\AgentDecisionStatisticsService::buildStatistics()} computes them: the title
 * line and the sections in print order. A console command of the application renders them as console tables, the admin
 * MCP tool as Markdown.
 */
class AgentDecisionStatistics extends ValueObject implements LlmMarkdownRenderable
{
    /** @var string Site, window, cutoff and whether eval traffic is excluded. */
    public string $title = '';

    /** @var AgentDecisionStatisticsSection[] The sections in print order. */
    public array $sections = [];

    public function addSection(AgentDecisionStatisticsSection $section): void
    {
        $this->sections[] = $section;
    }

    public function toLlmMarkdown(): string
    {
        $parts = ['# ' . $this->title];
        foreach ($this->sections as $section) {
            $parts[] = $section->toLlmMarkdown();
        }
        return implode("\n", $parts);
    }
}
