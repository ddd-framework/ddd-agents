<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Reports;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The OWNER REPORT of one unattended run, composed purely from the run conversation's PERSISTED state — the single
 * source of truth for what the customer actually received (the e-mail composer renders exactly this; the agent's
 * `getTaskRunReport` reads exactly this): subject = the newest resolved `send_owner_report` call's stored args,
 * body = the OWNER_REPORT chat message → the final assistant text → the run-report template as last resort.
 * Built by {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService::composeOwnerReportOfRun()}.
 */
class AIConversationOwnerReport extends ValueObject implements LlmMarkdownRenderable
{
    /** @var string The body came from the run's OWNER_REPORT chat message (what the customer saw in chat and by mail). */
    public const string BODY_SOURCE_OWNER_REPORT_MESSAGE = 'OWNER_REPORT_MESSAGE';

    /** @var string No report message was materialized — the body is the run's final assistant text. */
    public const string BODY_SOURCE_FINAL_ASSISTANT_TEXT = 'FINAL_ASSISTANT_TEXT';

    /** @var string The run produced no text at all — the body is the generic run-ended-unexpectedly template. */
    public const string BODY_SOURCE_TEMPLATE = 'TEMPLATE';

    /** @var int The run conversation this report belongs to. */
    public int $conversationId = 0;

    /** @var string The report subject (the `send_owner_report` subject; empty when the run never named one). */
    public string $subject = '';

    /** @var string The report body as Markdown, untruncated. */
    public string $bodyMarkdown = '';

    /** @var string Where the body came from: OWNER_REPORT_MESSAGE | FINAL_ASSISTANT_TEXT | TEMPLATE. */
    public string $bodySource = self::BODY_SOURCE_TEMPLATE;

    public function hasSubject(): bool
    {
        return trim($this->subject) !== '';
    }

    public function toLlmMarkdown(): string
    {
        $lines = [];
        if ($this->hasSubject()) {
            $lines[] = '**Subject:** ' . trim($this->subject);
        }
        if ($this->bodySource !== self::BODY_SOURCE_OWNER_REPORT_MESSAGE) {
            $lines[] = $this->bodySource === self::BODY_SOURCE_FINAL_ASSISTANT_TEXT
                ? '_(no separate report was written — this is the run\'s final answer)_'
                : '_(the run produced no report text — this is the generic run-ended notice)_';
        }
        $lines[] = trim($this->bodyMarkdown);
        return implode("\n\n", $lines);
    }

    /**
     * The report's structure in reading order: each Markdown heading (`# … ######`, outside widget blocks) as
     * `heading: <text>` and each widget block as `widget: <type>` — what two reports of one schedule share when their
     * structure is kept. Pure.
     *
     * @return string[]
     */
    public function buildStructureLines(): array
    {
        $structureLines = [];
        $offset = 0;
        foreach (WidgetBlocks::fromMarkdown($this->bodyMarkdown)->getElements() as $widgetBlock) {
            $structureLines = [...$structureLines, ...self::readHeadingLines(substr($this->bodyMarkdown, $offset, max(0, $widgetBlock->start - $offset)))];
            $widgetFields = $widgetBlock->getFields();
            $structureLines[] = 'widget: ' . ($widgetFields !== null ? (string)($widgetFields->type ?? '?') : '?');
            $offset = $widgetBlock->start + $widgetBlock->length;
        }
        return [...$structureLines, ...self::readHeadingLines(substr($this->bodyMarkdown, $offset))];
    }

    /** @return string[] the Markdown headings of `$prose` as `heading: <text>` lines */
    protected static function readHeadingLines(string $prose): array
    {
        preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', $prose, $headingMatches);
        return array_map(static fn (string $headingText): string => 'heading: ' . $headingText, $headingMatches[1]);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->conversationId);
    }
}
