<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAttributeCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSendsMessageToOwner;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantDimension;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantDimensions;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;

/**
 * The `Schedule.Run` purpose: one firing of an {@see AIConversationSchedule} — the unattended run
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService::spawnRunConversationForClaimedSchedule()}
 * creates for an instant, timed or recurring task. The seed USER message stays the owner's mission (the task text plus
 * the previous run's result line); this purpose carries everything the RUN as such has to know: that it executes and
 * never administers a schedule, how its report reaches the owner, and that the tools which send the owner a message
 * are not its delivery path ({@see getToolNamesBlockedByDeclaration()}).
 *
 * The delivery rules differ by report channel, so the directive carries one `## [reportChannel=…]` section per channel
 * and this class declares the ONE variant dimension that selects it: `reportChannel`, read from the conversation's own
 * snapshot column ({@see AIConversation::$reportChannel}, stamped at spawn, never changed by a later schedule edit).
 * A record fact, decided, never proposed — there is no owner to confirm it.
 */
class ScheduleRunPurpose extends AIConversationPurpose
{
    /** The variant dimension selecting the delivery section of the directive. */
    public const string DIMENSION_REPORT_CHANNEL = 'reportChannel';

    /**
     * A run is over once its report has reached its channel: an EMAIL run when the
     * delivery claim is consumed (`reportDeliveredAt` — a REPORT queued for mail or a NOTHING_TO_REPORT outcome, the
     * owed-reports sweep's late delivery included), a CHAT run when its turn settled (the final answer IS the report
     * in the task list). The loop then closes the conversation (IDLE → COMPLETED): it accepts no further owner input
     * — a question about the report is a new conversation (the mail's "Discuss report" opens one on the report
     * message) — and the scorecard files the run as `completed` instead of `abandoned`.
     */
    public function shouldCloseConversationAfterSettledTurn(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $reportChannel = isset($conversation->reportChannel) ? strtoupper(trim((string)$conversation->reportChannel)) : '';
        return $reportChannel !== AIConversationSchedule::REPORT_CHANNEL_EMAIL || isset($conversation->reportDeliveredAt);
    }

    /**
     * One delivery path per run: the platform carries the report over the schedule's report channel, so every tool
     * that sends the owner a message itself ({@see MCPToolSendsMessageToOwner}) is blocked for the run and its
     * delegation tree.
     *
     * @return string[]
     */
    public function getToolNamesBlockedByDeclaration(): array
    {
        return MCPToolAttributeCatalog::getToolNamesDeclaringAttribute(MCPToolSendsMessageToOwner::class);
    }

    public function getVariantDimensions(): AIConversationVariantDimensions
    {
        $dimensions = new AIConversationVariantDimensions();
        $reportChannelDimension = AIConversationVariantDimension::of(
            self::DIMENSION_REPORT_CHANNEL,
            [AIConversationSchedule::REPORT_CHANNEL_EMAIL, AIConversationSchedule::REPORT_CHANNEL_CHAT]
        );
        $dimensions->add($reportChannelDimension);
        return $dimensions;
    }

    public function resolveVariants(AIConversation $conversation, ?AIConversationVariants $previous = null, ?array $onlyDimensions = null): AIConversationVariants
    {
        $variants = new AIConversationVariants();
        $reportChannelDimension = $this->getVariantDimensions()->getByName(self::DIMENSION_REPORT_CHANNEL);
        $reportChannel = isset($conversation->reportChannel) ? strtoupper(trim((string)$conversation->reportChannel)) : '';
        $reportChannelVariant = $reportChannelDimension !== null && $reportChannelDimension->allowsValue($reportChannel)
            ? AIConversationVariant::decided(self::DIMENSION_REPORT_CHANNEL, $reportChannel, AIConversationVariant::SOURCE_RECORD)
            : AIConversationVariant::unknown(self::DIMENSION_REPORT_CHANNEL);
        $variants->put($reportChannelVariant);
        return $variants;
    }
}
