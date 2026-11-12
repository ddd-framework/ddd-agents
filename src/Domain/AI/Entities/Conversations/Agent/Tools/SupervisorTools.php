<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The tools of a supervisor checkpoint: three TERMINAL decisions, exactly one of
 * which ends the checkpoint, and the names of the four LOOKUPS an earlier version offered (no longer offered; stored
 * tandem rows still carry them). The schemas live here so the checkpoint executor and the tests build the identical
 * wire; the VALIDATION of a terminal call
 * ({@see \DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorDecisionToolArguments}) is structural (an
 * evidence index at or below the covered index, or a record reference from a `readRecord` lookup) — never a
 * wording rule, the AskOwnerTool precedent: degrade with feedback.
 */
class SupervisorTools extends ValueObject
{
    public const string READ_TOOL_RESULT = 'readToolResult';
    public const string READ_MESSAGE = 'readMessage';
    public const string READ_RECORD = 'readRecord';
    public const string LOAD_SKILL_CONTRACT = 'loadSkillContract';
    public const string SILENT_APPROVE = 'silentApprove';
    public const string STEER = 'steer';
    public const string CORRECT = 'correct';

    public const array LOOKUP_TOOL_NAMES = [self::READ_TOOL_RESULT, self::READ_MESSAGE, self::READ_RECORD, self::LOAD_SKILL_CONTRACT];
    public const array TERMINAL_TOOL_NAMES = [self::SILENT_APPROVE, self::STEER, self::CORRECT];

    /**
     * The record kind the module itself knows: the task plan. The kinds of a purpose's own stored state are named by
     * its policy ({@see \DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorPolicy::getRecordKinds()}).
     */
    public const string RECORD_PLAN = 'plan';

    /** One decision call; a second only when the first produced no valid terminal call. */
    public const int MAX_MODEL_CALLS_PER_CHECKPOINT = 2;

    /** A settled beat may act on this many findings in one response — at most one of them an owner-visible `correct`. */
    public const int MAX_INTERVENTIONS_PER_SETTLED_BEAT = 3;

    public const int MAX_INSTRUCTION_CHARS = 240;
    public const int MAX_OWNER_SENTENCE_CHARS = 200;
    public const int MAX_EVIDENCE_CHARS = 160;
    public const int MAX_TOPIC_CHARS = 60;
    public const int MAX_REASON_CHARS = 120;

    /** A record reference the evidence may name instead of a message index (`record:<kind>`). */
    public const string EVIDENCE_RECORD_PREFIX = 'record:';

    /** Result markers the executor writes on a terminal call row. */
    public const string RESULT_ACCEPTED = 'ACCEPTED';
    public const string RESULT_VALIDATION_FAILED = 'VALIDATION FAILED — no intervention';
    public const string RESULT_SECOND_CORRECTION_SILENT = 'made silently: the owner already read one correction on this record';
    public const string RESULT_DUPLICATE_TOPIC = 'DUPLICATE TOPIC — no intervention';
    public const string RESULT_CORRECT_ON_STEP_BEAT = 'CONVERTED TO STEER — a step beat may not open a correction turn';
    public const string RESULT_BUDGET_EXHAUSTED = 'NO INTERVENTION — lookup budget exhausted without a decision';

    public static function isLookupToolName(string $toolName): bool
    {
        return in_array($toolName, self::LOOKUP_TOOL_NAMES, true);
    }

    public static function isTerminalToolName(string $toolName): bool
    {
        return in_array($toolName, self::TERMINAL_TOOL_NAMES, true);
    }

    /**
     * The OpenAI tool schemas of one checkpoint — the THREE terminal decisions only (one
     * call per checkpoint, the thinking does the work, the visible output stays minimal; the facts a lookup used to
     * fetch are precomputed in the briefing). Offered with `tool_choice: required`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function toOpenAiToolSchemas(): array
    {
        $violatedProperty = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'The violated criterion codes, e.g. ["C5"] — empty on a step beat.',
        ];
        $evidenceProperty = [
            'type' => 'string',
            'maxLength' => self::MAX_EVIDENCE_CHARS,
            'description' => 'The supervised message indexes in brackets ([12] [15]) and/or record:<kind>; a few words at most.',
        ];
        $topicProperty = [
            'type' => 'string',
            'maxLength' => self::MAX_TOPIC_CHARS,
            'description' => 'The affected record and its index, e.g. goal@54 — one intervention per record and interaction.',
        ];
        $expectedToolProperty = ['type' => 'string', 'description' => 'The first tool the move needs (exact name), or empty when the move is a question or text.'];
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => self::SILENT_APPROVE,
                    'description' => 'No contradiction — no intervention. Call it without arguments.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['reason' => ['type' => 'string', 'maxLength' => self::MAX_REASON_CHARS, 'description' => 'Optional, a few words.']],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::STEER,
                    'description' => 'The agent executes ONE move at its next step (silently — the owner never sees the instruction).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'instruction' => ['type' => 'string', 'maxLength' => self::MAX_INSTRUCTION_CHARS, 'description' => 'ONE imperative move with the exact values, short.'],
                            'expectedTool' => $expectedToolProperty,
                            'violated' => $violatedProperty,
                            'evidence' => $evidenceProperty,
                            'topic' => $topicProperty,
                        ],
                        'required' => ['instruction', 'evidence', 'topic'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::CORRECT,
                    'description' => 'Settled beat only: the owner already READ something the record contradicts — the agent runs the mandate and tells the owner in one short message that it noticed and fixed a mistake.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'mandate' => ['type' => 'string', 'maxLength' => self::MAX_INSTRUCTION_CHARS, 'description' => 'The fix to run now, with the exact values, short.'],
                            'ownerSentence' => ['type' => 'string', 'maxLength' => self::MAX_OWNER_SENTENCE_CHARS, 'description' => 'What the owner must learn, in substance, one sentence.'],
                            'expectedTool' => $expectedToolProperty,
                            'violated' => $violatedProperty,
                            'evidence' => $evidenceProperty,
                            'topic' => $topicProperty,
                        ],
                        'required' => ['mandate', 'ownerSentence', 'evidence', 'topic'],
                    ],
                ],
            ],
        ];
    }
}
