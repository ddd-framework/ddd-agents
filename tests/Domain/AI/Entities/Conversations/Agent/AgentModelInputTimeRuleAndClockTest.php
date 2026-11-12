<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentModelInputTailEntry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The time rule is a STABLE system segment (cacheable prefix; an unconfirmed zone's note rides the tail), the clock is a VOLATILE tail signal
 * rendered from the ONE captured instant (never a fresh "now"), and the wire bracket of a user message carries the
 * zoned send time; a legacy conversation (no zone) keeps today's wire byte-for-byte.
 */
class AgentModelInputTimeRuleAndClockTest extends UnitTestCase
{
    protected function context(?string $timezone, bool $unverified = false): AgentModelInput
    {
        $agentContext = new AgentModelInput();
        $agentContext->systemPrompt = 'BASE';
        $agentContext->timezone = $timezone !== null ? new \DateTimeZone($timezone) : null;
        $agentContext->timezoneUnverified = $unverified;
        return $agentContext;
    }

    protected function segmentTextsByType(AgentModelInput $agentContext): array
    {
        $texts = [];
        foreach ($agentContext->buildSystemSegments() as $segment) {
            $texts[$segment->type->value] = $segment->text;
        }
        return $texts;
    }

    public function testTimeRuleSegmentNamesTheZoneAndItsProvenance(): void
    {
        $verified = $this->segmentTextsByType($this->context('America/New_York'));
        self::assertArrayHasKey(AgentContextSegmentType::TIME_RULE->value, $verified);
        self::assertSame("\n\n# Time\nTimes are the workspace's local time: America/New_York.", $verified[AgentContextSegmentType::TIME_RULE->value]);

        // the zone's provenance rides the tail: the time rule is the same bytes verified or not
        $unverifiedContext = $this->context('Europe/Berlin', unverified: true);
        $unverified = $this->segmentTextsByType($unverifiedContext);
        self::assertSame("\n\n# Time\nTimes are the workspace's local time: Europe/Berlin.", $unverified[AgentContextSegmentType::TIME_RULE->value]);
        self::assertSame(
            '[SIGNAL:time-zone] Europe/Berlin is the country default — the owner has not confirmed it; before you persist a clock time — a schedule, a post time, opening hours — ask the owner once which time zone they mean; relative delays need no confirmation.',
            $unverifiedContext->timeZoneUnconfirmedTailHint()
        );
        self::assertNull($this->context('America/New_York')->timeZoneUnconfirmedTailHint(), 'a verified zone needs no note');

        // a schedule-bound run cannot ask the owner — its unverified zone is worded as the schedule's, no ask-once clause
        $scheduleBoundContext = $this->context('UTC', unverified: true);
        $scheduleBoundContext->isScheduleBound = true;
        $scheduleBound = $this->segmentTextsByType($scheduleBoundContext);
        self::assertSame("\n\n# Time\nTimes are the workspace's local time: UTC.", $scheduleBound[AgentContextSegmentType::TIME_RULE->value]);
        self::assertSame("[SIGNAL:time-zone] UTC is the schedule's zone — not confirmed by the owner.", $scheduleBoundContext->timeZoneUnconfirmedTailHint());

        $legacy = $this->segmentTextsByType($this->context(null));
        self::assertArrayNotHasKey(AgentContextSegmentType::TIME_RULE->value, $legacy);
    }

    public function testTheTimeZoneNoteRidesTheTailBeforeTheClock(): void
    {
        $agentContext = $this->context('Europe/Berlin', unverified: true);
        $agentContext->clockInstant = DateTime::fromTimestamp(1_789_000_000);

        $tailEntryKinds = array_map(
            static fn (AgentModelInputTailEntry $agentModelInputTailEntry): string => $agentModelInputTailEntry->kind,
            $agentContext->buildTailEntries(null, false)->getElements()
        );

        self::assertSame([AgentModelInputTailEntry::KIND_TIME_ZONE, AgentModelInputTailEntry::KIND_CLOCK, AgentModelInputTailEntry::KIND_REPLY_LANGUAGE_CUE], $tailEntryKinds);
    }

    public function testTimeRuleIsStableAndTheClockNeverEntersTheSystemContent(): void
    {
        $agentContext = $this->context('America/New_York');
        $systemContentBefore = $agentContext->composeSystemContent();
        $agentContext->clockInstant = DateTime::fromTimestamp(1_789_000_000);
        self::assertSame($systemContentBefore, $agentContext->composeSystemContent(), 'the clock is volatile and must not touch the cacheable prefix');
    }

    public function testClockTailHintRendersTheCapturedInstantInTheZoneWithWeekday(): void
    {
        $agentContext = $this->context('America/New_York');
        self::assertNull($agentContext->clockTailHint(), 'no captured instant → no signal');
        $agentContext->clockInstant = DateTime::fromTimestamp((new \DateTimeImmutable('2026-07-01 12:00:00', new \DateTimeZone('UTC')))->getTimestamp());
        self::assertSame('[SIGNAL:clock] now = 2026-07-01 08:00:00-04:00 (Wednesday)', $agentContext->clockTailHint());

        $legacy = $this->context(null);
        $legacy->clockInstant = DateTime::fromTimestamp(1_789_000_000);
        self::assertNull($legacy->clockTailHint(), 'a legacy conversation gets no clock signal');
    }

    public function testUserMessageWireBracketCarriesTheZonedSendTimeOrTheLegacyForm(): void
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = AIConversationMessage::ROLE_USER;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Hallo';
        $message->getChangeHistory()->createdTime = DateTime::fromTimestamp((new \DateTimeImmutable('2026-07-01 12:34:56', new \DateTimeZone('UTC')))->getTimestamp());

        self::assertSame('[2026-07-01 08:34:56-04:00] Hallo', $message->getOpenAiMessageContent(false, new \DateTimeZone('America/New_York')));
        self::assertSame('[2026-07-01 12:34] Hallo', $message->getOpenAiMessageContent(), 'legacy: app clock, minute precision, no offset');
        // the in-message language cue is gone — the owner message renders byte-identical
        // whether or not it is the newest one; the `[SIGNAL:reply-language]` tail cue carries the instruction.
        self::assertSame(
            ['role' => 'user', 'content' => '[2026-07-01 21:34:56+09:00] Hallo'],
            $message->toOpenAiChatMessageArray(true, new \DateTimeZone('Asia/Tokyo'))
        );
        self::assertSame(
            $message->toOpenAiChatMessageArray(false, new \DateTimeZone('Asia/Tokyo')),
            $message->toOpenAiChatMessageArray(true, new \DateTimeZone('Asia/Tokyo')),
            'newest or not — the same bytes (the prompt-cache prefix never rewrites an owner message)'
        );
    }
}
