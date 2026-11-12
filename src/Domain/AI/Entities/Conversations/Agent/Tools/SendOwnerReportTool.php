<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The outcome call of an EMAIL run (the outcome contract; call first, then the report): the model states the OUTCOME — REPORT (the message it writes next is the report; the platform mails it
 * with its widgets rendered as images) or
 * NOTHING_TO_REPORT (the run closes quietly, no mail). The decision used to be "call the send tool or do not call it";
 * Luna treated the call as the run's closing ritual, called it with nothing to say and wrote "as requested, I did not
 * send an email" into the mail. A closed choice that is ALWAYS called removes the inaction path.
 * Execution is loop-level ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService::executeOwnerReportSendAtLoopLevel()}).
 *
 * @phpstan-consistent-constructor
 */
class SendOwnerReportTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'send_owner_report';

    /** The effect the stored-call readers see ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const ?string DECLARED_EFFECT = MCPToolEffect::WRITE;

    /** @var int A subject is ONE short line — longer is rejected with corrective feedback (cap-and-tell, never silent truncation). */
    public const int SUBJECT_MAX_CHARS = 150;

    /** @var int Body cap — rejected with corrective feedback when exceeded (cap-and-tell). */
    public const int BODY_MAX_CHARS = 8000;

    /** The run produced something the owner should read: the message written after the call is mailed. */
    public const string OUTCOME_REPORT = 'REPORT';
    /** The check found nothing the task defines as worth reporting: the run closes quietly, no mail goes out. */
    public const string OUTCOME_NOTHING_TO_REPORT = 'NOTHING_TO_REPORT';
    public const array OUTCOMES = [self::OUTCOME_REPORT, self::OUTCOME_NOTHING_TO_REPORT];

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    public static function create(): static
    {
        return new static();
    }

    /**
     * VALIDATION ONLY — the send itself (claim + visible report message + mail dispatch) runs at LOOP level
     * after the dispatch returns (never in the dispatch's branch, whose catch(Throwable) would demote a claim-lost throw
     * to a FAILED result while the claim is already consumed). Strict + corrective, cap-and-tell. An accepted call gets
     * its canonical normalized values back on its arguments: the loop-level send and the email composer (persisted-args
     * derivation) read exactly these.
     *
     * @param array<string, mixed> $arguments the call's arguments as dispatched
     */
    public static function answerCall(ToolCall $toolCall, array $arguments): AgentMetaToolCallAnswer
    {
        $sendOwnerReportOutcomeArguments = self::validateOutcomeArguments($arguments);
        if (!$sendOwnerReportOutcomeArguments->isAccepted()) {
            return AgentMetaToolCallAnswer::failed('Not accepted: ' . implode(' ', $sendOwnerReportOutcomeArguments->feedback));
        }
        $toolCall->arguments['outcome'] = $sendOwnerReportOutcomeArguments->outcome;
        // overwritten by the loop-level send with the definitive result
        $metaToolCallAnswer = AgentMetaToolCallAnswer::completed('Validated.');
        if ($sendOwnerReportOutcomeArguments->isReport()) {
            $toolCall->arguments['subject'] = $sendOwnerReportOutcomeArguments->subject;
            if ($toolCall->ownerReportMessageId === null) {
                // REPORT declared before the report (the contract: call first, then the report): the ordering belongs
                // to the platform — the next step runs tool-less so the model writes the report NOW, and the loop
                // binds that text to this call (appendTurn).
                $metaToolCallAnswer->withToolLessAnswerStep();
            }
        } else {
            unset($toolCall->arguments['subject']);
        }
        return $metaToolCallAnswer;
    }

    /**
     * Bind the report text to the step's `send_owner_report` calls before any call executes. The contract is "call first,
     * then the report" (247 of 260 delivered runs wrote it that way): a call is accepted without a report and
     * the next step is forced tool-less, so the report the model then writes binds to the call
     * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService::deliverOwnerReportWrittenAfterTheCall()}).
     * The text the model writes beside the call is never bound: it was "I'll send the report now." in practice, and
     * bound as the report it was mailed as such while the complete report written next was lost. Only the forced
     * closing step binds at once: its call follows the final text it was forced after.
     *
     * @param ToolCall[] $emittedToolCalls the step's calls
     * @param AIConversationMessage|null $reportSourceOfForcedStep the final text the forced closing step follows, null on every other step
     */
    public static function bindReportSourceToCalls(array $emittedToolCalls, ?AIConversationMessage $reportSourceOfForcedStep): void
    {
        foreach ($emittedToolCalls as $emittedToolCall) {
            if ($emittedToolCall->name === self::NAME) {
                $emittedToolCall->ownerReportMessageId = $reportSourceOfForcedStep?->id;
            }
        }
    }

    /** a COMPLETED (validated) call is sent at LOOP level — never inside the dispatch's catch. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return $toolCall->status === AIConversationMessage::STATUS_COMPLETED ? AgentAfterStepRequest::sendOwnerReport() : null;
    }

    /** No work request: the tool answers its call itself. */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        return null;
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> the OpenAI function-tool wire entry */
    public function toOpenAiToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'State the OUTCOME of an email run once the work is done, BEFORE you write the '
                    . 'report. The platform decides whether an email goes out — you never send anything yourself, so '
                    . 'never write that you did or did not send an email. REPORT: supply the subject; your NEXT message '
                    . 'is the complete report (prose and widgets) and the platform emails exactly that message. '
                    . 'NOTHING_TO_REPORT: the check found nothing the task defines as worth reporting; no subject, no '
                    . 'report, the platform closes the run without an email. Examples of '
                    . 'NOTHING_TO_REPORT: a mentions check finds no mention newer than the last run; a reviews check '
                    . 'finds no new or unanswered review; a rankings check sees no keyword that moved; a listings '
                    . 'check finds every directory still in sync; the task says "only if something changed" and '
                    . 'nothing changed. Examples of REPORT: one new mention, even a neutral one; a review that '
                    . 'needs an answer; a keyword that dropped; a post that failed to publish; anything the task '
                    . 'asked to be told about. Call this exactly once, after all other work is done.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'outcome' => [
                            'type' => 'string',
                            'enum' => self::OUTCOMES,
                            'description' => 'REPORT when the run found something worth the owner\'s attention — the '
                                . 'report follows as your next message; NOTHING_TO_REPORT when the check found nothing '
                                . 'the task defines as worth reporting.',
                        ],
                        'subject' => [
                            'type' => 'string',
                            'description' => 'REPORT only: ONE short email subject line stating the outcome, in the '
                                . 'owner\'s language (max ' . self::SUBJECT_MAX_CHARS . ' chars). Omit for NOTHING_TO_REPORT.',
                        ],
                    ],
                    'required' => ['outcome'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * STRICT argument validation with corrective feedback (the update_task_plan lesson — never a silent drop
     * or silent truncation): alias-tolerant keys, newline-stripped one-line subject, hard caps named in the
     * rejection so the model can resend. A non-empty feedback list means REJECTED — nothing may be sent.
     *
     * @param array<string, mixed> $arguments
     * @return SendOwnerReportLegacyArguments
     */
    public static function validateArguments(array $arguments): SendOwnerReportLegacyArguments
    {
        $feedback = [];
        $subject = trim((string)($arguments['subject'] ?? $arguments['title'] ?? ''));
        $subject = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $subject)) ?? $subject);
        $body = trim((string)($arguments['body'] ?? $arguments['report'] ?? $arguments['text'] ?? ''));
        if ($subject === '') {
            $feedback[] = "'subject' is missing or empty — send ONE short subject line stating the outcome.";
        } elseif (mb_strlen($subject) > self::SUBJECT_MAX_CHARS) {
            $feedback[] = "'subject' is " . mb_strlen($subject) . ' chars — the maximum is ' . self::SUBJECT_MAX_CHARS . '. Resend a shorter subject.';
        }
        if ($body === '') {
            $feedback[] = "'body' is missing or empty — send the full report as markdown.";
        } elseif (mb_strlen($body) > self::BODY_MAX_CHARS) {
            $feedback[] = "'body' is " . mb_strlen($body) . ' chars — the maximum is ' . self::BODY_MAX_CHARS . '. Resend a tighter report (summarize long lists).';
        }
        return SendOwnerReportLegacyArguments::forSubjectBodyAndFeedback($subject, $body, $feedback);
    }

    /**
     * The outcome contract: `outcome` is REQUIRED and one of {@see OUTCOMES}; `subject` is required with REPORT and
     * refused with NOTHING_TO_REPORT (a subject there means the model wrote a report it then declared empty — the
     * feedback makes it choose). The report body is never an argument: it is the message the model writes after the call, and the
     * recipient is the platform's. The legacy validator remains available for persisted pre-cutover calls.
     *
     * @param array<string, mixed> $arguments
     * @return SendOwnerReportOutcomeArguments
     */
    public static function validateOutcomeArguments(array $arguments): SendOwnerReportOutcomeArguments
    {
        $feedback = [];
        $outcome = $arguments['outcome'] ?? null;
        $outcome = is_string($outcome) ? strtoupper(trim($outcome)) : '';
        if (!in_array($outcome, self::OUTCOMES, true)) {
            $feedback[] = "'outcome' is missing or unknown — send REPORT (the report follows as your next message) "
                . 'or NOTHING_TO_REPORT (the check found nothing the task defines as worth reporting).';
        }
        $subject = $arguments['subject'] ?? null;
        if ($subject !== null && (!is_string($subject) || !mb_check_encoding($subject, 'UTF-8'))) {
            $feedback[] = "'subject' must be a UTF-8 string containing one short email subject line.";
            $subject = '';
        }
        $subject = trim(preg_replace('/\s+/u', ' ', (string)$subject) ?? '');
        if ($outcome === self::OUTCOME_REPORT) {
            if ($subject === '') {
                $feedback[] = "'subject' is missing or empty — REPORT needs one short email subject line.";
            } elseif (mb_strlen($subject) > self::SUBJECT_MAX_CHARS) {
                $feedback[] = "'subject' exceeds " . self::SUBJECT_MAX_CHARS . ' characters — shorten it.';
            } elseif (preg_match('/[\x00-\x1f\x7f]/u', $subject)) {
                $feedback[] = "'subject' contains an invalid control character.";
            }
        } elseif ($outcome === self::OUTCOME_NOTHING_TO_REPORT && $subject !== '') {
            $feedback[] = 'NOTHING_TO_REPORT takes no subject — nothing is emailed. If the run DID find something worth '
                . 'reporting, send REPORT with a subject instead and write the report as your next message.';
        }
        if (array_diff(array_keys($arguments), ['outcome', 'subject']) !== []) {
            $feedback[] = 'Supply only outcome and subject. The report is your next message after this call; '
                . 'the platform selects that message and the configured recipient.';
        }
        return SendOwnerReportOutcomeArguments::forOutcomeSubjectAndFeedback($outcome, $subject, $feedback);
    }
}
