<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The activity-line narrator: ONE call writes the customer-facing line for up to a handful of tool calls of a running
 * turn — what each is doing (from its cut arguments) or what came of it (from the head of its result text) — in
 * the conversation's language. A one-shot JSON generation on GPT-6 Luna without reasoning, direct OpenRouter egress, an 8 s transport ceiling (a late answer leaves the lines null for the next
 * request), budget not enforced (a budget-exhausted account still sees that its agent works; the endpoint caps the
 * volume). Luna over the FAST_CHEAP scope (gpt-oss-120b) by measurement on a live conversation: gpt-oss
 * drifted into English on a third of the finished lines, leaked a widget fence and quoted e-mail addresses at twice
 * the cost; Luna stayed in the customer's language. Without reasoning: 2.2 s instead of 4.8 s per call of 8
 * elements, the same lines; Luna may append its reasoning as prose behind the JSON object, which
 * {@see firstJsonObject()} tolerates.
 *
 * `$aiModelNameOverride` names a catalog model for a comparison run (the activity-lines console command); without it
 * the scope resolves the model.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 8
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT6_LUNA,
    fastMode: true,
    reasoningEffort: ArgusOpenAIReasoningEffort::NONE,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_ACTIVITY_LINES,
    temperature: 0.2,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_AGENT_NARRATE_ACTIVITY,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    enforceBudget: false,
)]
class ArgusAgentActivityLines extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait {
        getAIModel as protected getAIModelFromScope;
    }

    /** @var AgentActivityLines The elements to narrate; their `line` is filled by {@see applyLoadResult()}. */
    public AgentActivityLines $activityLines;

    /** @var string The customer's last message, cut. */
    public string $customerMessage = '';

    /** @var string The language the lines are written in (a language name or code as the prompt expects). */
    public string $language = 'the language of the customer\'s request';

    /** @var string The assistant's own earlier reply, cut: the lines mirror its form of address and voice. */
    public string $assistantSample = '';

    /** @var string[] The lines already read in this turn. */
    public array $alreadyToldLines = [];

    /** @var Entity|null The entity within the account the AI costs of this call are billed to (the billing entity of the conversation's space). */
    public ?Entity $billingEntity = null;

    /** @var string|null A catalog model name for a comparison run; null = the attribute decides. */
    public ?string $aiModelNameOverride = null;

    /** @var string|null A reasoning effort for a comparison run; null = the attribute decides. */
    public ?string $reasoningEffortOverride = null;

    /** @var bool True once the model returned a parseable object with at least one line. */
    public bool $narrated = false;

    /** @var string|null The raw answer when it was not the expected JSON object; null when it parsed. */
    public ?string $unparsedResult = null;

    public function __construct()
    {
        parent::__construct();
        $this->activityLines = new AgentActivityLines();
    }

    public function getAIModel(): ?AIModel
    {
        if ($this->aiModelNameOverride !== null && $this->aiModelNameOverride !== '' && !isset($this->aiModel)) {
            /** @var AIModelsService $aiModelsService */
            $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
            $resolved = $aiModelsService->getAIModelByName($this->aiModelNameOverride);
            if ($resolved !== null) {
                $this->aiModel = $resolved;
            }
        }
        return $this->getAIModelFromScope();
    }

    public function getArgusLanguageModelInstance(): ?ArgusLanguageModel
    {
        if (isset($this->argusLanguageModel)) {
            return $this->argusLanguageModel;
        }
        $instance = static::getReflectionClass()->getAttributeInstance(ArgusLanguageModel::class);
        if ($instance !== null && $this->reasoningEffortOverride !== null && $this->reasoningEffortOverride !== '') {
            // The attribute instance is shared per class; a per-object override works on its own copy.
            $instance = clone $instance;
            $instance->reasoningEffort = $this->reasoningEffortOverride;
        }
        $this->argusLanguageModel = $instance;
        return $this->argusLanguageModel;
    }

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        }
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('CUSTOMER_MESSAGE', $this->customerMessage !== '' ? $this->customerMessage : '(none)');
        $prompt->setParameter('LANGUAGE', $this->language);
        $prompt->setParameter('ASSISTANT_SAMPLE', $this->assistantSample !== '' ? $this->assistantSample : '(none yet: address the customer the way the customer wrote, informal where the language has the choice)');
        $prompt->setParameter('ALREADY_TOLD', $this->alreadyToldLines === [] ? '(nothing yet)' : '- ' . implode("\n- ", $this->alreadyToldLines));
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        return 'ELEMENTS:' . "\n" . $this->buildElementsJson();
    }

    /** The elements as the narrator sees them: id, state, kind, what the call does, and the result head when it has returned. */
    protected function buildElementsJson(): string
    {
        $elements = [];
        foreach ($this->activityLines->getElements() as $activityLine) {
            if (!$activityLine->wantsLine()) {
                continue;
            }
            $element = [
                'id' => $activityLine->toolCallMessageId,
                'state' => $activityLine->state,
                'kind' => $activityLine->kind,
                'does' => $activityLine->narratorToolName,
                'arguments' => $activityLine->narratorArguments,
            ];
            if ($activityLine->kind === AgentActivityLine::KIND_DELEGATION) {
                $element['helpers'] = $activityLine->childCount;
            }
            if ($activityLine->helperIndex !== null) {
                $element['helper'] = $activityLine->helperIndex;
            }
            if ($activityLine->narratorResultHead !== null) {
                $element['resultHead'] = $activityLine->narratorResultHead;
            }
            $elements[] = $element;
        }
        return (string)json_encode($elements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The first balanced JSON object in the answer. A model at a low reasoning effort may follow the object with
     * prose or wrap it in a code fence; the object itself is what is parsed.
     */
    protected function firstJsonObject(string $text): string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return '';
        }
        $depth = 0;
        $inString = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        return substr($text, $start);
    }

    protected function applyLoadResult(string $resultText): void
    {
        if (trim($resultText) === '') {
            return;
        }
        $decoded = json_decode($this->firstJsonObject($resultText), true);
        if (!is_array($decoded) || !is_array($decoded['lines'] ?? null)) {
            $this->unparsedResult = mb_substr($resultText, 0, 2000);
            return;
        }
        $byId = [];
        foreach ($this->activityLines->getElements() as $activityLine) {
            $byId[$activityLine->toolCallMessageId] = $activityLine;
        }
        foreach ($decoded['lines'] as $entry) {
            if (!is_array($entry) || !is_string($entry['line'] ?? null)) {
                continue;
            }
            $id = (int)($entry['id'] ?? 0);
            $line = trim($entry['line']);
            if ($id <= 0 || $line === '' || !isset($byId[$id]) || !$byId[$id]->wantsLine()) {
                continue;
            }
            // The cap is enforced here, not trusted from the model: a line that runs long is cut at a word.
            if (mb_strlen($line) > 120) {
                $line = rtrim(mb_substr($line, 0, 119)) . '…';
            }
            $byId[$id]->line = $line;
            $this->narrated = true;
        }
    }
}
