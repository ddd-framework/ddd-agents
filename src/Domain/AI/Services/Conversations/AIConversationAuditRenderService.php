<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The deterministic AUDIT blocks of a conversation — what a reviewer reads instead of recounting or inferring from
 * prose: the write ledger, the outward actions, the widgets the owner saw, the task plan with provenance, the live
 * state of the space. Shared by the host's eval judge and the
 * supervisor's settled briefing (what the judge can see, the supervisor must see).
 */
class AIConversationAuditRenderService extends Service
{
    /**
     * Names of OUTWARD tools of the application that no longer exist — no definition declares them any more, but
     * stored conversations still hold their calls, and the audit of such a conversation lists them as before. The
     * module knows none; an application returns its own on its service class of the same path.
     *
     * @return string[]
     */
    public function getRemovedOutwardActionToolNames(): array
    {
        return [];
    }

    /**
     * True when a call of the named tool is an OUTWARD or consent-bound action, listed separately: the tool declares it
     * ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect::$isOutwardAction}), it is the loop's own
     * `send_owner_report`, or it is a removed outward tool of a stored conversation
     * ({@see getRemovedOutwardActionToolNames()}).
     */
    public static function isOutwardActionToolName(string $toolName): bool
    {
        if (MCPToolEffectCatalog::isOutwardActionToolName($toolName) || $toolName === SendOwnerReportTool::NAME) {
            return true;
        }
        /** @var AIConversationAuditRenderService $aIConversationAuditRenderService */
        $aIConversationAuditRenderService = DDDService::instance()->getService(AIConversationAuditRenderService::class);
        return in_array($toolName, $aIConversationAuditRenderService->getRemovedOutwardActionToolNames(), true);
    }

    /** A read / search / proposal / validation persists nothing. */
    public static function isReadOnlyToolName(string $toolName): bool
    {
        foreach (['get', 'search', 'propose', 'resolve', 'validate', 'check', 'list', 'find'] as $readPrefix) {
            if (str_starts_with($toolName, $readPrefix)) {
                return true;
            }
        }
        return false;
    }

    /** Every COMPLETED write of the run with its index and arguments (capped per line). */
    public function renderWriteLedger(AIConversation $conversation, int $argumentsMaxChars = 220): string
    {
        $ledgerLines = [];
        foreach (($conversation->messages?->getElements() ?? []) as $i => $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            $toolName = (string)($toolCall?->name ?? '');
            if ($toolCall === null || $toolName === '' || self::isReadOnlyToolName($toolName)
                || in_array($toolName, TaskPlan::PLAN_TOOL_NAMES, true) || $toolName === 'load_skills'
                || $toolCall->status !== AIConversationMessage::STATUS_COMPLETED || $toolCall->wasRefusedAsDuplicate
            ) {
                continue;
            }
            $ledgerLines[] = sprintf('- [%d] %s(%s)', $message->indexInConversation ?? $i, $toolName, mb_substr($toolCall->argumentsAsJson(), 0, $argumentsMaxChars));
        }
        return "[WRITE LEDGER — every COMPLETED write of the run, index + arguments; a save the chat claims that is not "
            . "listed here did not happen]\n" . ($ledgerLines !== [] ? implode("\n", $ledgerLines) : '(no writes)');
    }

    /**
     * The outward / consent-bound actions of the run. The headline names the kinds of outward actions and the
     * exception to the owner's explicit yes in the application's words ({@see AgentApplicationWording}).
     */
    public function renderOutwardActions(AIConversation $conversation): string
    {
        $actionLines = [];
        foreach (($conversation->messages?->getElements() ?? []) as $i => $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            $toolName = (string)($toolCall?->name ?? '');
            if ($toolCall === null || !self::isOutwardActionToolName($toolName)) {
                continue;
            }
            $actionLines[] = sprintf('- [%d] %s(%s) [%s]', $message->indexInConversation ?? $i, $toolName, mb_substr($toolCall->argumentsAsJson(), 0, 220), (string)($toolCall->status ?? '?'));
        }
        $agentApplicationWording = AgentApplicationWording::current();
        $outwardActionKinds = $agentApplicationWording->outwardActionKinds !== '' ? $agentApplicationWording->outwardActionKinds . '; ' : '';
        $consentException = $agentApplicationWording->outwardActionConsentException !== ''
            ? ' — exception ' . $agentApplicationWording->outwardActionConsentException
            : '';
        return '[OUTWARD ACTIONS — ' . $outwardActionKinds . "each needs the owner's explicit yes in a USER row BEFORE it"
            . $consentException . "]\n" . ($actionLines !== [] ? implode("\n", $actionLines) : '(none)');
    }

    /**
     * Who wrote a widget block, as the judge's texts name it: the tool or the platform its
     * `emittedBy` names, else the model — a block on a widget row (`$isOnWidgetRow`, a TOOL_MESSAGE) without the field
     * was stored before the field and is named as such, never guessed from the row's `signalSource`.
     */
    public static function describeWidgetWriter(ConversationWidgetOrigin $conversationWidgetOrigin, bool $isOnWidgetRow): string
    {
        return match (true) {
            $conversationWidgetOrigin->isTool() => 'the TOOL ' . $conversationWidgetOrigin->toolName . ' (a widget row)',
            $conversationWidgetOrigin->isPlatform() => 'the PLATFORM (a widget row, reason: ' . $conversationWidgetOrigin->platformReason . ')',
            $isOnWidgetRow => 'a TOOL or the PLATFORM (a widget row stored without its origin)',
            default => 'the MODEL (fence in its text)',
        };
    }

    /**
     * Every widget the OWNER saw, in order, with who wrote it ({@see describeWidgetWriter()}) and what the owner can do
     * on it (the class's `OWNER_CONTROL`, read through {@see ConversationWidgetClasses}; "unknown" when none).
     */
    public function renderWidgetInventory(AIConversation $conversation): string
    {
        $inventoryLines = [];
        foreach (($conversation->messages?->getElements() ?? []) as $i => $message) {
            $messageType = $message->messageType ?? null;
            if (!in_array($messageType, [AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::MESSAGE_TOOL_MESSAGE], true)
                || ($message->role ?? null) === AIConversationMessage::ROLE_USER
            ) {
                continue;
            }
            // every block WidgetBlocks reads: fenced (``` or ~~~, an info string, an
            // unclosed fence included), bare (the one-line ```` ```widget {…}``` ```` included) and stored leaf blocks;
            // a block without JSON (a container, an unreadable leaf, an empty fence) is skipped, a malformed body is
            // still listed as "(invalid JSON)"; a fence indented four spaces is no block, a ```widget glued to prose is
            // one when a JSON object follows on the next line (the frontend's repair)
            // origin from the block: `emittedBy` on a widget row, the model on any other row
            // ({@see AIConversationMessage::buildWidgetBlockOrigins()}, same blocks, same order)
            $widgetBlockOrigins = $message->buildWidgetBlockOrigins();
            $isWidgetRow = $messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE;
            foreach (array_values($message->buildWidgetBlocks()->getElements()) as $blockPosition => $widgetBlock) {
                if ($widgetBlock->widgetJson === '') {
                    continue;
                }
                $widgetPayload = $widgetBlock->getFieldsAsArray();
                $widgetType = is_array($widgetPayload) ? (string)($widgetPayload['type'] ?? '?') : '(invalid JSON)';
                $inventoryLines[] = sprintf(
                    '- [%d] %s — written by %s — owner control: %s',
                    $message->indexInConversation ?? $i,
                    $widgetType,
                    self::describeWidgetWriter($widgetBlockOrigins[$blockPosition] ?? ConversationWidgetOrigin::model(), $isWidgetRow),
                    ConversationWidgetClasses::getOwnerControlOfWidgetType($widgetType) ?? 'unknown'
                );
            }
        }
        return "[WIDGETS THE OWNER SAW — every rendered widget in order, with who wrote it and what the owner can do on it; "
            . "this list is AUTHORITATIVE for every 'shown' / 'presented' / 'the owner could change' judgement — prose, "
            . "status notes and tool results are NOT widgets]\n" . ($inventoryLines !== [] ? implode("\n", $inventoryLines) : '(no widgets at all)');
    }

    /** The newest task plan as the judge reads it — status, note and the renderer's provenance suffixes. */
    public function renderTaskPlanWithProvenance(AIConversation $conversation): ?string
    {
        $taskPlan = $conversation->getAgentWorkingState()?->getNewestTaskPlan();
        if ($taskPlan?->items === null) {
            return null;
        }
        return "[TASK PLAN — status + statusNote per item: [✓]=done, [✗ blocked: …], [– n/a: …]=not_applicable, [ ]=open. "
            . 'PROVENANCE suffixes written by the RENDERER, never by the agent: (platform-resolved @N) = the PLATFORM closed the leaf '
            . 'from live data after message N — provenance, not a pass; ' . TaskPlanItem::newInstance()->getJudgeLegendOfBlockedMark()
            . "(evidence @N) = a tick accepted on the delivered answer N. The word \"system\" inside a statusNote is NOT provenance.]\n"
            . $taskPlan->toJudgeText();
    }

    /**
     * The LIVE state of what the conversation works for, read through the reads of the supervised purpose's policy
     * ({@see \DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorPolicy::getAgentSpaceStateReads()} — the
     * records the application keeps for the space), every cache switched off — what is really stored
     * right now.
     *
     * Each read runs as a harness call for `$agentSpace` ({@see AgentToolCallProvenance::forEvalHarness()}). No
     * target argument is written here: the executor fills the arguments of the space from the provenance
     * ({@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::applyAgentSpaceDefaultsToToolArguments()}, which
     * needs the classes of the tool method's parameters — only the executor has them), so a read that names its
     * target by an id of the application's own entity gets it the same way as in the agent loop.
     *
     * @param AgentSpace|null $agentSpace the space whose state is read; null = account level: no argument is filled
     *                                    and the reads run for the account alone (a read that cannot answer without
     *                                    a space says so in its own section, the others are rendered)
     * @param AIConversation|null $conversation the conversation the reads are made for (its decided variants and
     *                                          owner rows are what a read sees); null = none
     * @param int $maxCharsPerRead the cap of one read's section
     * @return string
     */
    public function renderAgentSpaceState(?AgentSpace $agentSpace, ?AIConversation $conversation = null, int $maxCharsPerRead = 6000): string
    {
        /** @var SupervisorCheckpointService $supervisorCheckpointService */
        $supervisorCheckpointService = DDDService::instance()->getService(SupervisorCheckpointService::class);
        $agentSpaceStateReads = $supervisorCheckpointService->findSupervisorPolicyFor($conversation)->getAgentSpaceStateReads();
        $cacheFlags = [ArgusLoad::$deactivateArgusCache, DoctrineEntityRegistry::$clearCache, VirtualEntityRegistry::$clearCache, DDDService::$noCache];
        ArgusLoad::$deactivateArgusCache = true;
        DoctrineEntityRegistry::$clearCache = true;
        VirtualEntityRegistry::$clearCache = true;
        DDDService::$noCache = true;
        DoctrineEntityRegistry::clear();
        EntityManagerFactory::clearAllInstanceCaches();
        $stateSections = [];
        try {
            /** @var MCPToolExecutorService $executor */
            $executor = DDDService::instance()->getService(MCPToolExecutorService::class);
            foreach ($agentSpaceStateReads as $agentSpaceStateRead) {
                $toolName = $agentSpaceStateRead->toolName;
                try {
                    $stateResult = $executor->execute($toolName, [], $agentSpaceStateRead->packageClassNames, null, AgentToolCallProvenance::forEvalHarness($agentSpace, $conversation));
                    $stateDecoded = json_decode($stateResult->content, true);
                    $stateText = is_array($stateDecoded) ? (string)($stateDecoded['content'][0]['text'] ?? $stateResult->content) : $stateResult->content;
                    $stateSections[] = "## $toolName\n" . mb_substr($stateText, 0, $maxCharsPerRead);
                } catch (Throwable $stateReadError) {
                    $stateSections[] = "## $toolName\n(read failed: " . $stateReadError->getMessage() . ')';
                }
            }
        } finally {
            [ArgusLoad::$deactivateArgusCache, DoctrineEntityRegistry::$clearCache, VirtualEntityRegistry::$clearCache, DDDService::$noCache] = $cacheFlags;
        }
        return implode("\n\n", $stateSections);
    }
}
