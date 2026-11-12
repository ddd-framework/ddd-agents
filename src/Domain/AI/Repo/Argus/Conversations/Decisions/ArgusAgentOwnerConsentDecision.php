<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
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

    public const string STATE_STEP = 'step';
    public const string STATE_CONSEQUENCE = 'consequence';
    public const string STATE_DIALOGUE = 'dialogue';
    public const string STATE_CHANNEL = 'channel';

    public const string CHANNEL_CHAT = 'chat';
    public const string CHANNEL_PHONE = 'phone';

    /** A dialogue line is cut to this many characters (an assistant explanation keeps its END, where the question stands). */
    public const int MAX_LINE_CHARS = 700;

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

    /** The decision for one request over one dialogue. */
    public static function forRequest(OwnerConsentRequest $request, OwnerConsentDialogue $dialogue, string $channel): static
    {
        $decision = new static();
        $decision->step = $request->targetLine;
        $decision->consequence = $request->consequenceLine;
        $decision->dialogue = $dialogue;
        $decision->channel = $channel;
        return $decision;
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
            . 'WIDGET lines are answer widgets the assistant showed, OWNER lines are the owner\'s own words (typed, a picked widget '
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
                    . 'that takes the yes back, narrows it or adds a condition replaces the earlier yes. ' . $dialogueRule
                    . ' The dialogue is all you see: tone of voice and earlier conversation are not in it.',
                'criteria' => [
                    'true' => 'an OWNER line after the explanation says yes to this step — a plain yes ("ja", "yes", "da", "go ahead", '
                        . '"ja bitte, mach das"), a picked widget option that means yes to it ("Ja, ändern"), or a yes that repeats '
                        . 'the target — and no later OWNER line takes it back; a later line about something else ("and update '
                        . 'the phone number too", "no thanks" to a different offer) leaves the yes standing',
                    'false' => 'the owner asks a question ("what does that mean exactly?"); sets a condition or a later time ("yes, but '
                        . 'next week", "only if Google keeps my reviews"); agrees to something else or to only part of it (one of '
                        . 'two logos, another address); only acknowledges ("ok, I see", "hm, I don\'t know"); says yes ironically '
                        . '("sure, delete everything, great idea…"); says no; speaks before the assistant explained the step '
                        . '("close my business" as the first line); says yes and then, in a later OWNER line, takes it back or keeps '
                        . 'part of it ("no wait, actually don\'t", "then keep the winter one"); or only an ASSISTANT line claims '
                        . 'the agreement',
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
                // line starts with the question that names the step and its target
                'text' => $dialogueLine->speaker === OwnerConsentDialogueLine::SPEAKER_ASSISTANT
                    ? mb_substr($dialogueLine->text, -self::MAX_LINE_CHARS)
                    : mb_substr($dialogueLine->text, 0, self::MAX_LINE_CHARS),
            ];
        }
        return [
            self::STATE_STEP => $this->step,
            self::STATE_CONSEQUENCE => $this->consequence,
            self::STATE_DIALOGUE => $dialogueState,
            self::STATE_CHANNEL => $this->channel,
        ];
    }

    protected function buildQuestionsForRequest(): array
    {
        return self::buildQuestions();
    }

    protected function applyAnswers(object $answers): bool
    {
        $this->stepExplainedProbability = $this->readNoulProbability($answers, self::QUESTION_STEP_EXPLAINED);
        $this->ownerAgreedProbability = $this->readNoulProbability($answers, self::QUESTION_OWNER_AGREED);
        return $this->stepExplainedProbability !== null && $this->ownerAgreedProbability !== null;
    }
}
