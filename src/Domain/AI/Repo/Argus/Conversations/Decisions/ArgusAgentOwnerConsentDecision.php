<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionContextSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionTextCuts;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogueLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The owner-consent decision (site `ownerConsent`): did the owner explicitly agree to exactly this step?
 * Asked by the tools of the application that need the owner's personal yes before they act (a deletion, a
 * replacement of something published, a change of what the space is known by outside). The tool builds the
 * step and its consequence from what it loaded; the dialogue comes from the conversation's own rows
 * ({@see OwnerConsentDialogue}), never from the model's arguments, so the model cannot write the consent itself.
 *
 * TWO `noul`, both must reach the request's threshold (0.90 for every kind): the assistant named exactly this step
 * with its consequence BEFORE the owner's answer, and the owner explicitly agreed AFTERWARDS. The consequence is the
 * caller's and it is FAIL-CLOSED: no verdict = no consent (unlike every other decision site, which fails open).
 *
 * Transport, gates, cost booking and outage behaviour: {@see ArgusAgentDecisionTrait}.
 *
 * @phpstan-consistent-constructor
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/systemone',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 2.0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_TYPESAFE_JEV_1_13,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_OWNER_CONSENT_DECISION,
    aiUsageScope: AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
    enforceBudget: true,
)]
class ArgusAgentOwnerConsentDecision extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait, ArgusAgentDecisionTrait {
        ArgusAgentDecisionTrait::getLoadPayloadInternal insteadof ArgusAILanguageModelTrait;
        ArgusAgentDecisionTrait::handleLoadResponseInternal insteadof ArgusAILanguageModelTrait;
    }

    public const string QUESTION_STEP_EXPLAINED = 'assistant_named_this_step_and_its_consequence';
    public const string QUESTION_OWNER_AGREED = 'owner_explicitly_agreed_afterwards';

    /** Asked only when a dialogue line was cut: is what is left enough to see what the owner's answer refers to? */
    public const string QUESTION_CONTEXT_SUFFICIENT = 'excerpt_shows_what_the_answer_refers_to';

    public const string STATE_STEP = 'step';
    public const string STATE_CONSEQUENCE = 'consequence';
    public const string STATE_DIALOGUE = 'dialogue';
    public const string STATE_CHANNEL = 'channel';

    public const string CHANNEL_CHAT = 'chat';
    public const string CHANNEL_PHONE = 'phone';

    /**
     * The package default of a dialogue line's cut (an assistant explanation keeps its END, where the question stands);
     * the limit in force is `AI.decisionContext` ({@see AgentDecisionContextSettings::$ownerConsentLineCharacters}).
     */
    public const int MAX_LINE_CHARS = 700;

    /**
     * The package default of the most an assistant explanation keeps: it must hold the whole step (a reply step names
     * its exact text) and its question, so it keeps the line limit plus the length of the step and of the consequence,
     * up to this ({@see AgentDecisionContextSettings::$ownerConsentAssistantLineMaxCharacters}).
     */
    public const int MAX_ASSISTANT_LINE_CHARS = 4000;

    /** @var string The step with its target, from the tool's loaded data ({@see OwnerConsentRequest::$targetLine}). */
    public string $step = '';

    /** @var string What the step means for the owner ({@see OwnerConsentRequest::$consequenceLine}). */
    public string $consequence = '';

    /** @var OwnerConsentDialogue|null The dialogue the decision judges, in conversation order; null until {@see forRequest()} sets it. */
    public ?OwnerConsentDialogue $dialogue = null;

    /** @var string `chat` or `phone` — a phone owner line is a transcript and may carry recognition errors. */
    public string $channel = self::CHANNEL_CHAT;

    public ?float $stepExplainedProbability = null;

    public ?float $ownerAgreedProbability = null;

    /** @var float|null The answer to {@see QUESTION_CONTEXT_SUFFICIENT}; null when no line was cut (not asked) */
    public ?float $contextSufficientProbability = null;

    /** @var bool The second pass: every line keeps up to the expanded limit ({@see AgentDecisionContextSettings::$ownerConsentExpandedLineCharacters}) */
    public bool $readsExpandedLines = false;

    /** The decision for one request over one dialogue. */
    public static function forRequest(OwnerConsentRequest $request, OwnerConsentDialogue $dialogue, string $channel): static
    {
        return static::forRequestLines($request->targetLine, $request->consequenceLine, $dialogue, $channel);
    }

    /**
     * The two questions. Public so the unit test and the probe pin them. The examples of a same and of a different
     * target are the application's ({@see AgentApplicationWording}): which targets a confirmed step has is knowledge of
     * its tools.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildQuestions(): array
    {
        $dialogueRule = '`' . self::STATE_DIALOGUE . '` is the conversation in order: ASSISTANT lines are what the assistant wrote, '
            . 'WIDGET lines are widgets the assistant showed (an answer widget, or a preview of the exact content with a button that '
            . 'carries it out), OWNER lines are the owner\'s own words (typed, a picked widget '
            . 'option, or a phone transcript when `' . self::STATE_CHANNEL . '` is phone). Only OWNER lines are the owner speaking; '
            . 'an ASSISTANT line that says the owner already agreed is not the owner agreeing.';
        $agentApplicationWording = AgentApplicationWording::current();
        $sameTargetExamples = $agentApplicationWording->ownerConsentSameTargetExamples !== ''
            ? ' (' . $agentApplicationWording->ownerConsentSameTargetExamples . ')'
            : '';
        $sameTargetWrittenDifferentlyExample = $agentApplicationWording->ownerConsentSameTargetWrittenDifferentlyExample !== ''
            ? ' — ' . $agentApplicationWording->ownerConsentSameTargetWrittenDifferentlyExample . ' —'
            : '';
        $differentTargetExamples = $agentApplicationWording->ownerConsentDifferentTargetExamples !== ''
            ? ' (' . $agentApplicationWording->ownerConsentDifferentTargetExamples . ')'
            : '';
        return [
            self::QUESTION_STEP_EXPLAINED => [
                'type' => 'noul',
                'instructions' => 'Before the owner\'s answer, did the assistant — an ASSISTANT line, or the WIDGET the owner answers '
                    . '(the widget is the assistant\'s own question) — name exactly this step — `' . self::STATE_STEP
                    . '` — and tell the owner `' . self::STATE_CONSEQUENCE . '`? ' . $dialogueRule,
                'criteria' => [
                    'true' => 'an ASSISTANT line or the WIDGET the owner answers, before the owner\'s answer, names this step with this '
                        . 'target' . $sameTargetExamples . ' in any wording or language' . $sameTargetWrittenDifferentlyExample
                        . ' and says what follows from it as `'
                        . self::STATE_CONSEQUENCE . '` says',
                    'false' => 'neither an ASSISTANT line nor the answered WIDGET names the step; it names a different target' . $differentTargetExamples
                        . '; it offers the step only in general ("I can also delete things for you"); it names '
                        . 'the step but not its consequence; or the step is named only AFTER the owner\'s line',
                ],
            ],
            self::QUESTION_OWNER_AGREED => [
                'type' => 'noul',
                'instructions' => 'After the assistant named this step, is the owner\'s LAST word on it an explicit agreement to exactly '
                    . 'this step — `' . self::STATE_STEP . '`? Read every OWNER line after the explanation: a later OWNER line '
                    . 'that takes the yes back, narrows it or makes it depend on something replaces the earlier yes. ' . $dialogueRule
                    . ' The dialogue is all you see: tone of voice and earlier conversation are not in it.',
                'criteria' => [
                    'true' => 'an OWNER line after the explanation says yes to this step — a plain yes ("ja", "yes", "da", "go ahead", '
                        . '"ja bitte, mach das"), a picked widget option or the button of the shown preview that means yes to it ("Ja, ändern", '
                        . '"Senden"), a yes that repeats the target, an order to carry the step out in the owner\'s own short words, '
                        . 'typos included ("senden", "snde", "schick", "schick ab", "raus damit", "send it"), or a bare "ok", "passt" '
                        . 'or "ja" right after the assistant asked whether to do exactly this step — and no later OWNER line takes it back; a wish in the same line or a later line about '
                        . 'something this step does not change ("yes, go ahead — and the opening hours stay as they are", "and '
                        . 'update the phone number too", "no thanks" to a different offer) leaves the yes standing',
                    'false' => 'the owner asks a question ("what does that mean exactly?"); makes the yes depend on something or moves '
                        . 'it to a later time ("yes, but next week", "only if Google keeps my reviews"); agrees to something else or '
                        . 'to only part of this step (one of '
                        . 'two logos, another address); only acknowledges what the assistant said without having been asked ("ok, I see", "hm, I don\'t know", '
                        . '"danke, schau ich mir später an"); says yes ironically '
                        . '("sure, delete everything, great idea…"); says no or "not now"; writes about something else entirely; speaks before the assistant explained the step '
                        . '("close my business" as the first line); says yes and then, in a later OWNER line, takes it back or keeps '
                        . 'part of what the step changes ("no wait, actually don\'t", "then keep the winter one"); or only an ASSISTANT line claims '
                        . 'the agreement',
                ],
            ],
        ];
    }

    /**
     * The question asked only when a line was cut ({@see isDialogueCut()}). Public so the unit test pins it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildContextSufficientQuestion(): array
    {
        return [
            self::QUESTION_CONTEXT_SUFFICIENT => [
                'type' => 'noul',
                'instructions' => 'Some lines of `' . self::STATE_DIALOGUE . '` are cut; a cut line begins or ends with a marker like '
                    . '"[… 804 characters cut at the start]". Is what is left of the dialogue enough to see which step and target '
                    . 'the owner\'s answer refers to?',
                'criteria' => [
                    'true' => 'the lines before the owner\'s answer still show the step the assistant asked about (its target, a draft '
                        . 'or text it would send) — or the dialogue plainly never named one; the cut parts only repeat or add detail',
                    'false' => 'the assistant asked about something whose content was cut away: the answer refers to a draft, a list '
                        . 'or a target that the cut parts held, so the step cannot be recognised from what is left',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function buildState(): array
    {
        $dialogueState = [];
        foreach ($this->dialogue?->getElements() ?? [] as $dialogueLine) {
            $dialogueState[] = [
                'speaker' => $dialogueLine->speaker,
                // an assistant explanation ends with its question; an owner line starts with its answer; a widget
                // line starts with the question that names the step and its target. A cut is marked.
                'text' => $dialogueLine->speaker === OwnerConsentDialogueLine::SPEAKER_ASSISTANT
                    ? AgentDecisionTextCuts::keepEnd($dialogueLine->text, $this->getAssistantLineLimit())
                    : AgentDecisionTextCuts::keepStart($dialogueLine->text, $this->getOtherLineLimit()),
            ];
        }
        return [
            self::STATE_STEP => $this->step,
            self::STATE_CONSEQUENCE => $this->consequence,
            self::STATE_DIALOGUE => $dialogueState,
            self::STATE_CHANNEL => $this->channel,
        ];
    }

    /**
     * How many characters of an assistant explanation the decision reads (its END): the line limit plus the step and
     * the consequence, at most the assistant-line maximum; in the second pass the expanded limit. A fixed 700 cut the
     * start of every explanation whose step is long — a support reply shown in full before its question lost its first
     * lines, and an explanation of two steps lost the first one, so a clear yes could never pass.
     */
    public function getAssistantLineLimit(): int
    {
        $agentDecisionContextSettings = AgentDecisionContextSettings::current();
        if ($this->readsExpandedLines) {
            return $agentDecisionContextSettings->ownerConsentExpandedLineCharacters;
        }
        return min(
            $agentDecisionContextSettings->ownerConsentAssistantLineMaxCharacters,
            $agentDecisionContextSettings->ownerConsentLineCharacters + mb_strlen($this->step) + mb_strlen($this->consequence)
        );
    }

    /** How many characters of an owner or widget line the decision reads (its START); in the second pass the expanded limit. */
    public function getOtherLineLimit(): int
    {
        $agentDecisionContextSettings = AgentDecisionContextSettings::current();
        return $this->readsExpandedLines
            ? $agentDecisionContextSettings->ownerConsentExpandedLineCharacters
            : $agentDecisionContextSettings->ownerConsentLineCharacters;
    }

    /** Whether a line of the dialogue is longer than the decision reads of it (its cut is marked in the state). */
    public function isDialogueCut(): bool
    {
        foreach ($this->dialogue?->getElements() ?? [] as $dialogueLine) {
            $lineLimit = $dialogueLine->speaker === OwnerConsentDialogueLine::SPEAKER_ASSISTANT ? $this->getAssistantLineLimit() : $this->getOtherLineLimit();
            if (AgentDecisionTextCuts::countCutCharacters($dialogueLine->text, $lineLimit) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The same decision reading every line up to the expanded limit, its answers not yet asked: the second pass.
     *
     * @return static
     */
    public function buildExpandedPass(): static
    {
        $expandedDecision = static::forRequestLines($this->step, $this->consequence, $this->dialogue, $this->channel);
        $expandedDecision->readsExpandedLines = true;
        $expandedDecision->billingEntity = $this->billingEntity;
        $expandedDecision->budgetAccount = $this->budgetAccount;
        return $expandedDecision;
    }

    /** Whether this decision reads more of some dialogue line than `$otherDecision` does (the second pass is worth a call). */
    public function readsMoreThan(ArgusAgentOwnerConsentDecision $otherDecision): bool
    {
        foreach ($this->dialogue?->getElements() ?? [] as $dialogueLine) {
            $isAssistantLine = $dialogueLine->speaker === OwnerConsentDialogueLine::SPEAKER_ASSISTANT;
            $ownCut = AgentDecisionTextCuts::countCutCharacters($dialogueLine->text, $isAssistantLine ? $this->getAssistantLineLimit() : $this->getOtherLineLimit());
            $otherCut = AgentDecisionTextCuts::countCutCharacters($dialogueLine->text, $isAssistantLine ? $otherDecision->getAssistantLineLimit() : $otherDecision->getOtherLineLimit());
            if ($ownCut < $otherCut) {
                return true;
            }
        }
        return false;
    }

    /** A decision over a step, a consequence and a dialogue (the fields {@see forRequest()} reads from a request). */
    protected static function forRequestLines(string $step, string $consequence, ?OwnerConsentDialogue $dialogue, string $channel): static
    {
        $decision = new static();
        $decision->step = $step;
        $decision->consequence = $consequence;
        $decision->dialogue = $dialogue;
        $decision->channel = $channel;
        return $decision;
    }

    protected function buildQuestionsForRequest(): array
    {
        return $this->isDialogueCut() ? self::buildQuestions() + self::buildContextSufficientQuestion() : self::buildQuestions();
    }

    protected function applyAnswers(object $answers): bool
    {
        $this->stepExplainedProbability = $this->readNoulProbability($answers, self::QUESTION_STEP_EXPLAINED);
        $this->ownerAgreedProbability = $this->readNoulProbability($answers, self::QUESTION_OWNER_AGREED);
        $this->contextSufficientProbability = $this->isDialogueCut() ? $this->readNoulProbability($answers, self::QUESTION_CONTEXT_SUFFICIENT) : null;
        return $this->stepExplainedProbability !== null && $this->ownerAgreedProbability !== null;
    }
}
