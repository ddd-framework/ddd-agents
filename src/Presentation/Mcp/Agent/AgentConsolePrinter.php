<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Environment\AgentApplicationNames;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentTurn;
use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use Throwable;

/**
 * Prints an agent conversation to the terminal (Presentation; implements the Domain {@see AgentMessagePrinterInterface}
 * port). Extracted from the agent loop service so the loop holds no rendering / stream state — it only hands finished
 * messages to this printer (live streaming) and the CLI command uses it directly. No domain state; pure message →
 * console formatting. The port's public alias (`#[AsAlias]`): the loop resolves the printer through `getService()` by
 * the interface name.
 */
#[AsAlias(AgentMessagePrinterInterface::class, public: true)]
class AgentConsolePrinter extends Service implements AgentMessagePrinterInterface
{
    /** Below this length a tool result still prints inline (un-collapsed); above it, only a one-line summary unless --full. */
    protected const int RESULT_INLINE_MAX = 200;

    /** Content hashes of long messages already shown in a prior --debug payload — collapse the re-sends turn over turn. */
    protected array $seenDebugMessageHashes = [];

    /** Last tools list rendered in a --debug payload, to collapse the unchanged tool catalog turn over turn. */
    protected ?string $lastToolNamesDebug = null;

    /**
     * @var array<string, string> The base system prompt per rendered conversation (keyed like the status-line state),
     * resolved once for the --debug payload reconstruction.
     */
    protected array $baseSystemPromptsByConversationKey = [];

    /** Lazily-built Markdown→ANSI renderer for assistant text (pure helper, no DI). */
    protected ?ConsoleMarkdownRenderer $markdownRenderer = null;

    /**
     * Render-time status-line dedupe state: true once a status-only announcement line
     * ({@see AIConversationMessage::isStatusOnlyAnnouncementLine()}) rendered since the last USER message — a
     * second-or-later one is skipped (except under --full, where forensics see everything). Streaming renders
     * message by message, so the since-last-user-message tracking lives as printer state instead of a set
     * filter; it is KEYED per conversation ({@see $statusLineConversationKey}) so a delegation child rendered
     * mid-parent-turn (renderTurn recursion) neither consumes the parent's allowance nor resets it.
     */
    protected bool $statusLineRenderedSinceLastUserMessage = false;

    /** The conversation the dedupe state belongs to — a conversation switch resets it. */
    protected ?string $statusLineConversationKey = null;

    protected function markdownRenderer(): ConsoleMarkdownRenderer
    {
        return $this->markdownRenderer ??= new ConsoleMarkdownRenderer();
    }

    /**
     * Print one conversation message to a console output. Every line carries a message id (`[#<dbId>]`, or
     * `[#i<index>]` for non-persisted/ephemeral runs) and, for delegation (child) conversations, a delegation column
     * `D<anchorId> │` so it's always clear which delegation a step belongs to (the delegation context comes
     * from $conversation — `isDelegation()` / `delegatedFromMessage` —, not from the message). Tool results render
     * as an aligned continuation line. Shared by live streaming and by AgentChatCommand.
     */
    public function renderMessage(
        AIConversationMessage $message,
        OutputInterface $output,
        bool $full = false,
        bool $debug = false,
        bool $collapseThreads = false,
        ?AIConversation $conversation = null
    ): void {
        $isDelegation = $conversation?->isDelegation() ?? false;
        // --collapse-threads: hide each delegation's internal steps; the delegate call + its verdict remain
        // (they live in the PARENT conversation), so the delegation + result still show without the noise.
        if ($collapseThreads && $isDelegation) {
            return;
        }

        // Render-time status-line dedupe — mirrors the A2A wire filter; --full keeps
        // every message for forensics. State is per conversation: a delegation child rendered mid-parent-turn
        // must not consume or reset the parent's allowance.
        $renderedConversationKey = $conversation !== null ? (string)($conversation->id ?? spl_object_id($conversation)) : '';
        if ($renderedConversationKey !== $this->statusLineConversationKey) {
            $this->statusLineConversationKey = $renderedConversationKey;
            $this->statusLineRenderedSinceLastUserMessage = false;
        }
        if (($message->role ?? null) === AIConversationMessage::ROLE_USER) {
            $this->statusLineRenderedSinceLastUserMessage = false;
        }
        if (!$full && $message->isStatusOnlyAnnouncementLine()) {
            if ($this->statusLineRenderedSinceLastUserMessage) {
                return;
            }
            $this->statusLineRenderedSinceLastUserMessage = true;
        }

        // Message id: the persisted DB id, else the in-conversation index (so ephemeral runs still have a handle).
        $idToken = $message->id !== null ? '#' . $message->id : '#i' . ($message->indexInConversation ?? 0);
        $idTag = sprintf('<fg=gray>%-8s</>', '[' . $idToken . ']');
        $idPad = str_repeat(' ', 8); // continuation lines align under the body, past the id column

        $gutter = '';
        // $isDelegation = true implies $conversation is non-null (the nullsafe call above) — PHPStan narrows it.
        if ($isDelegation) {
            // isDelegation() above already loaded delegatedFromMessage (non-null here); isset guards the access.
            $delegatedFromMessage = isset($conversation->delegatedFromMessage) ? $conversation->delegatedFromMessage : null;
            $gutter = sprintf('<fg=magenta>D%d │</> ', isset($delegatedFromMessage->id) ? (int)$delegatedFromMessage->id : 0);
        }
        // Assistant TEXT renders through the Markdown→ANSI renderer (bold/headers/lists/tables): the assistant label
        // leads the first line, continuation lines align under the gutter. Everything else (user, tool, error) is
        // a single formatted line. Decoration follows the output — piped/redirected output gets plain text.
        if ($message->messageType === AIConversationMessage::MESSAGE_TEXT
            && $message->role !== AIConversationMessage::ROLE_USER
        ) {
            $label = '<comment>' . AgentApplicationNames::getConsoleAssistantLabel() . '></comment> ';
            // Render layer strips the {preCallMessage} sentinel (the backend stores it raw + only detects/signals on it).
            $assistantText = AIConversationMessage::stripPreCallMessageMarker((string)$message->message);
            $renderedLines = $this->markdownRenderer()->render($assistantText, $output->isDecorated());
            $firstLine = array_shift($renderedLines) ?? '';
            $output->writeln($idTag . ' ' . $gutter . $label . $firstLine);
            foreach ($renderedLines as $renderedLine) {
                $output->writeln($idPad . ' ' . $gutter . $renderedLine);
            }
        } else {
            $output->writeln($idTag . ' ' . $gutter . $this->formatMessage($message, $full, $isDelegation));
        }

        // Delegation lifecycle markers on the delegate call message: opener when the child starts (RUNNING),
        // closing child-run statistics when the verdict crossed back (terminal status + wired child edge).
        if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL
            && $message->toolCall !== null && ($message->toolCall->name ?? '') === DelegateTool::NAME
        ) {
            // 1:N — one delegate call may have spawned several children; render each child's stats.
            $childConversations = $message->getDelegatedConversationsIfLoaded();
            if (($message->toolCall->status ?? '') === AIConversationMessage::STATUS_RUNNING) {
                $output->writeln($idPad . ' ' . sprintf('<fg=magenta>┌─ D%d child conversation(s) started</>', (int)$message->id));
            } elseif ($childConversations !== [] && in_array($message->toolCall->status ?? '', [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED], true)) {
                foreach ($childConversations as $childIndex => $childConversation) {
                    $childMessages = $childConversation->messages;
                    $output->writeln($idPad . ' ' . sprintf(
                        '<fg=magenta>└─ D%d.%d child done:</> <fg=gray>%d messages · %d tool calls · skills [%s] · %d tokens · $%.4f</>',
                        (int)$message->id,
                        $childIndex + 1,
                        count($childMessages?->getElements() ?? []),
                        $childMessages?->buildToolCallHistory()->countToolCalls() ?? 0,
                        implode(', ', $childMessages?->getLoadedSkillNames() ?? []),
                        $childMessages?->getTotalTokens() ?? 0,
                        $childMessages?->getTotalCostUsd() ?? 0.0
                    ));
                }
            }
        }

        // Tool result on its own continuation line. Collapsed by default: a short result prints inline, a long one
        // shows only a one-line summary (size) so heavy dumps don't bury the trace — `--full` expands everything.
        $toolResult = isset($message->toolCall) ? $message->toolCall->result : null;
        if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL
            && $toolResult !== null && $toolResult !== ''
        ) {
            if ($full || mb_strlen($toolResult) <= self::RESULT_INLINE_MAX) {
                $output->writeln($idPad . ' ' . $gutter . '<fg=green>←</> ' . $toolResult);
            } else {
                $output->writeln($idPad . ' ' . $gutter . sprintf(
                    '<fg=green>←</> <fg=gray>%d chars collapsed — --full to expand</>',
                    mb_strlen($toolResult)
                ));
            }
        }
        // --debug: the skills this load_skills call pulled in, each with a shortened instruction preview — makes
        // visible WHAT guidance just entered the system prompt without dumping whole instruction blocks.
        if ($debug
            && $message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL
            && isset($message->toolCall) && ($message->toolCall->name ?? '') === LoadSkillsTool::NAME
            && $message->hasLoadedCapabilities()
        ) {
            foreach ($message->findLoadedAISkills()->getElements() as $skill) {
                $instructionPreview = trim(preg_replace('/\s+/', ' ', $skill->getInstructionBlock()) ?? '');
                $output->writeln($idPad . ' ' . $gutter . sprintf(
                    '<fg=cyan>skill %s</> <fg=gray>%s</>',
                    $skill->name,
                    mb_substr($instructionPreview, 0, 140) . (mb_strlen($instructionPreview) > 140 ? ' …' : '')
                ));
            }
        }
        if ($debug && isset($message->requestSnapshot) && $conversation !== null) {
            // The snapshot is slim (model/effort/sentAt only); the wire payload is no longer stored. Reconstruct it on
            // demand from the conversation's own rows + the compaction cover so --debug shows the FULL payload as sent.
            // No conversation in scope → nothing to reconstruct, skip (there is no stored payload to fall back to).
            /** @var AIConversationModelCallsService $aIConversationModelCallsService */
            $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
            // the base prompt (Agentic.md + the context text of the space) the turn was built on — without it the reconstructed
            // `[system]` block started at the per-call sections and never showed Agentic.md
            $debugPayload = $aIConversationModelCallsService->reconstructSentPayloadForDisplay(
                $conversation,
                $message,
                $this->findBaseSystemPromptOfConversation($conversation, $renderedConversationKey, $aIConversationModelCallsService)
            );
            if ($debugPayload !== []) {
                foreach ($this->formatDebugPayload($debugPayload) as $line) {
                    $output->writeln($idPad . ' ' . $gutter . '<fg=gray>' . $line . '</>');
                }
            }
        }
    }

    /**
     * The base system prompt of `$conversation` as a live turn resolves it
     * ({@see AIConversationModelCallsService::resolveBaseSystemPrompt()}, the admin sent-payload view's source), once per
     * conversation of this printer. Read-only for the conversation (the resolver caches the string in Redis at most); a
     * resolution that fails leaves the reconstruction without the base prompt, as before, instead of failing the print.
     */
    protected function findBaseSystemPromptOfConversation(
        AIConversation $conversation,
        string $renderedConversationKey,
        AIConversationModelCallsService $aIConversationModelCallsService
    ): string {
        if (!array_key_exists($renderedConversationKey, $this->baseSystemPromptsByConversationKey)) {
            try {
                $this->baseSystemPromptsByConversationKey[$renderedConversationKey] = $aIConversationModelCallsService->resolveBaseSystemPrompt($conversation);
            } catch (Throwable) {
                $this->baseSystemPromptsByConversationKey[$renderedConversationKey] = '';
            }
        }
        return $this->baseSystemPromptsByConversationKey[$renderedConversationKey];
    }

    /**
     * Replay the WHOLE conversation through the same {@see renderMessage} path the live loop uses, but into an
     * undecorated {@see BufferedOutput} — so the captured text is exactly what scrolled past in the terminal, minus
     * the ANSI colour codes (clipboards want plain text). Turns are walked via {@see AIConversation::buildAgentTurns()};
     * after each turn the matching token/cost/skills line is appended, and any delegation child turns this turn
     * caused are rendered inline (their own `D<id>` gutter) right after the turn that spawned them — mirroring how
     * the live loop streamed them. $full defaults to true so a copy carries the COMPLETE tool results.
     */
    public function renderTranscript(
        AIConversation $conversation,
        bool $full = true,
        bool $debug = false,
        bool $collapseThreads = false
    ): string {
        $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        foreach ($conversation->buildAgentTurns() as $turnIndex => $turn) {
            $this->renderTurn($turn, $conversation, $buffer, $full, $debug, $collapseThreads);
            $this->renderTurnMetricsLine($turn, $turnIndex, $buffer);
        }
        return $buffer->fetch();
    }

    /** Render one turn's slice messages, then recurse into each delegation child turn it caused. */
    protected function renderTurn(
        AgentTurn $turn,
        AIConversation $conversation,
        OutputInterface $output,
        bool $full,
        bool $debug,
        bool $collapseThreads
    ): void {
        foreach ($turn->messages as $message) {
            $this->renderMessage($message, $output, $full, $debug, $collapseThreads, $conversation);
            // 1:N — render each child conversation spawned by this delegate message.
            foreach ($message->getDelegatedConversationsIfLoaded() as $childConversation) {
                foreach ($childConversation->buildAgentTurns() as $childTurn) {
                    $this->renderTurn($childTurn, $childConversation, $output, $full, $debug, $collapseThreads);
                }
            }
        }
    }

    /** The dim per-turn accounting line, matching what AgentChatCommand prints live after each turn. */
    protected function renderTurnMetricsLine(AgentTurn $turn, int $turnIndex, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '· turn %d: %d tok · $%.4f · %d steps · %d delegations',
            $turnIndex,
            $turn->totalTokens(),
            $turn->totalCostUsd(),
            $turn->steps(),
            count($turn->delegations())
        ));
    }

    /**
     * Render the request payload for --debug as a readable block instead of one compact JSON wall. The raw payload
     * repeats the full tool JSON-schemas every turn (multi-KB) and inlines the LiteLLM api key — both are noise/risk
     * for a human reading the trace. So: show the model + sampling params, each message with its content as real
     * multi-line text (the system prompt becomes legible), tool_calls summarised, the tools array collapsed to its
     * names, and the api key redacted. Falls back to the (capped) raw JSON if the payload doesn't decode.
     *
     * @return string[] the lines to emit (already indent-free; the caller adds the gutter/pad)
     */
    protected function formatDebugPayload(array $payload): array
    {
        if (!is_array($payload['body'] ?? null)) {
            $raw = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return ['payload: ' . mb_substr($raw, 0, 2000) . (mb_strlen($raw) > 2000 ? ' …' : '')];
        }
        $body = $payload['body'];
        $params = [];
        foreach (['model', 'reasoning_effort', 'temperature', 'max_tokens', 'max_output_tokens', 'tool_choice'] as $key) {
            if (isset($body[$key]) && !is_array($body[$key])) {
                $params[] = $key . '=' . $body[$key];
            }
        }
        $lines = ['payload  ' . implode('  ', $params)];

        // a Responses body carries `input` items — printed in the same per-entry form
        $debugEntries = is_array($body['messages'] ?? null)
            ? $body['messages']
            : array_map([self::class, 'describeResponsesInputItemAsDebugEntry'], is_array($body['input'] ?? null) ? array_values(array_filter($body['input'], 'is_array')) : []);
        foreach ($debugEntries as $msg) {
            $role = is_array($msg) ? (string)($msg['role'] ?? '?') : '?';
            $content = is_array($msg) ? (is_string($msg['content'] ?? null) ? $msg['content'] : (string)json_encode($msg['content'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : '';
            // The wire payload re-sends the whole growing history (system prompt, loaded-skill briefings, tool
            // results) every turn — multi-KB blocks already printed by the loop above. Show a long message in full
            // only the first time its exact content appears; collapse later re-sends to a one-liner. Short content
            // and tool_calls are always shown (they're the cheap, turn-specific signal).
            $isLong = mb_strlen($content) > 200;
            $hash = $role . ':' . md5($content);
            if ($content !== '' && $isLong && isset($this->seenDebugMessageHashes[$hash])) {
                $lines[] = "  [$role] (repeated — " . mb_strlen($content) . ' chars)';
            } else {
                if ($isLong) {
                    $this->seenDebugMessageHashes[$hash] = true;
                }
                $lines[] = "  [$role]";
                foreach (explode("\n", $content) as $contentLine) {
                    $lines[] = '    ' . $contentLine;
                }
            }
            foreach (is_array($msg['tool_calls'] ?? null) ? $msg['tool_calls'] : [] as $call) {
                $fn = is_array($call) ? ($call['function'] ?? []) : [];
                $lines[] = '    → ' . (string)($fn['name'] ?? '?') . '(' . (string)($fn['arguments'] ?? '') . ')';
            }
        }

        // Tools: just the names — the full schemas are huge, repeat every turn, and the agent loop already prints
        // each tool call/result separately.
        $toolNames = [];
        foreach (is_array($body['tools'] ?? null) ? $body['tools'] : [] as $tool) {
            $fn = is_array($tool) ? ($tool['function'] ?? $tool) : [];
            $toolNames[] = (string)($fn['name'] ?? '?');
        }
        if ($toolNames !== []) {
            $joined = implode(', ', $toolNames);
            if ($joined === $this->lastToolNamesDebug) {
                $lines[] = '  tools: (unchanged — ' . count($toolNames) . ')';
            } else {
                $this->lastToolNamesDebug = $joined;
                $lines[] = '  tools: [' . $joined . ']';
            }
        }
        return $lines;
    }

    /**
     * One Responses `input` item as the debug entry the Chat printing reads: a message → its role and joined
     * text; a `function_call` → the call; a `function_call_output` → its output text; an `additional_tools` item → the
     * appended tool names; a `reasoning` item → its size only (the content is encrypted).
     *
     * @param array<string, mixed> $inputItem
     * @return array<string, mixed>
     */
    public static function describeResponsesInputItemAsDebugEntry(array $inputItem): array
    {
        $itemType = (string)($inputItem['type'] ?? 'message');
        if ($itemType === 'function_call') {
            return ['role' => 'function_call', 'content' => '', 'tool_calls' => [['function' => ['name' => $inputItem['name'] ?? '?', 'arguments' => $inputItem['arguments'] ?? '']]]];
        }
        if ($itemType === 'function_call_output') {
            return ['role' => 'function_call_output', 'content' => self::joinResponsesPartTexts($inputItem['output'] ?? '')];
        }
        if ($itemType === 'additional_tools') {
            $appendedToolNames = [];
            foreach (is_array($inputItem['tools'] ?? null) ? $inputItem['tools'] : [] as $appendedTool) {
                $appendedToolNames[] = is_array($appendedTool) ? (string)($appendedTool['name'] ?? '?') : '?';
            }
            return ['role' => 'additional_tools', 'content' => 'tools appended: ' . implode(', ', $appendedToolNames)];
        }
        if ($itemType === 'reasoning') {
            return ['role' => 'reasoning', 'content' => '(reasoning item, ' . strlen((string)json_encode($inputItem)) . ' chars)'];
        }
        return ['role' => (string)($inputItem['role'] ?? '?'), 'content' => self::joinResponsesPartTexts($inputItem['content'] ?? '')];
    }

    /** The text of a Responses content (a string or `input_text` / `output_text` parts; a picture reads as `[image]`). */
    protected static function joinResponsesPartTexts(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $partTexts = [];
        foreach (is_array($content) ? $content : [] as $contentPart) {
            if (is_array($contentPart)) {
                $partTexts[] = isset($contentPart['text']) ? (string)$contentPart['text'] : '[' . (string)($contentPart['type'] ?? 'part') . ']';
            }
        }
        return implode('', $partTexts);
    }

    /**
     * The primary (single-line) rendering of a message body — id, delegation gutter and tool result are added by
     * {@see renderMessage}. Inside a delegation, USER-role messages are the ORCHESTRATOR's task/follow-up
     * (polymorphic initiator) — rendered as `task>` so they are not mistaken for human input.
     */
    protected function formatMessage(AIConversationMessage $message, bool $full, bool $isDelegation = false): string
    {
        return match ($message->messageType) {
            AIConversationMessage::MESSAGE_TEXT => ($message->role === AIConversationMessage::ROLE_USER)
                ? ($isDelegation ? '<fg=magenta>task></> ' : '<info>you></info> ') . $message->message
                : '<comment>' . AgentApplicationNames::getConsoleAssistantLabel() . '></comment> ' . $message->message,
            AIConversationMessage::MESSAGE_TOOL_CALL => '<fg=yellow>→</> ' . $this->summariseToolCall($message),
            AIConversationMessage::MESSAGE_ERROR => "<error>✗ $message->message</error>",
            default => "<fg=gray>? $message->messageType:</> " . $this->truncate($message->message, $full),
        };
    }

    protected function summariseToolCall(AIConversationMessage $message): string
    {
        // Tool name / args / status all read off the message's ToolCall VO (the single source for tool-call state).
        $name = $message->toolCall->name ?? '?';
        $args = $message->toolCall->argumentsAsJson();
        $status = $message->toolCall->status ?? '';
        $line = "<options=bold>$name</>($args) <fg=gray>[$status]</>";
        // the message's names, never the property: loadedCapabilities stays UNINITIALIZED on a row whose column is NULL
        // (an update_task_plan call before any skill load), and an unread change row answers without building its set.
        $messageLoadedSkillNames = $message->getLoadedSkillNames();
        if ($messageLoadedSkillNames !== []) {
            $line .= ' <fg=cyan>{skills: ' . implode(', ', $messageLoadedSkillNames) . '}</>';
        }
        return $line;
    }

    protected function truncate(string $text, bool $full): string
    {
        if ($full || mb_strlen($text) <= self::RESULT_INLINE_MAX) {
            return $text;
        }
        return mb_substr($text, 0, self::RESULT_INLINE_MAX) . ' … <fg=gray>(' . mb_strlen($text) . ' chars, --full to expand)</>';
    }
}
