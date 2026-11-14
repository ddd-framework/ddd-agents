<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\BackgroundMechanicalOperationPurpose;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;

/**
 * A tool run: a sub-agent executes ONE call of {@see $toolName} with {@see $toolArguments} through the same executor,
 * rights, gates and budget as a direct call — without a model.
 *
 * Until the tool-run steps that change the stored shapes every rendering is
 * today's background-job form, byte for byte: the seed is the `executeTool` operation seed of
 * {@see BackgroundMechanicalOperationPurpose::buildOperationSeedText()}, the `delegate` call item is its label line with
 * the requester's skills, the purpose is `Background.MechanicalOperation`.
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationToolRunTask extends AgentDelegationTask
{
    /** The namespace a model's own function list puts before a tool name; a `toolName` written with it names the tool after it. */
    public const string FUNCTION_LIST_NAMESPACE_PREFIX = 'functions.';

    /** The tool the sub-agent calls. */
    public string $toolName = '';

    /** @var array<string, mixed> the arguments of that one call */
    public array $toolArguments = [];

    /** @var string[] the skills the requester picked for the seed (today's background operations name them); empty = the providing skill */
    public array $requestedSeedSkillNames = [];

    /**
     * The background operation the child runs instead of the call of {@see $toolName} — a `backgroundOperations` item
     * that is no plain tool call: an operation the application names, an
     * `executeTool` item without a tool name, or a name the purpose step passed through unchecked. Null = the tool call.
     * Rendered byte for byte as the requester's item ({@see buildChildSeedText()}).
     */
    public ?string $legacyBackgroundOperationName = null;

    /**
     * The cache entry a purpose warm-up computes (the purpose's `cacheIdentity`, e.g. the keyword proposal's key over the
     * record) — part of the warm's INPUT besides the tool arguments: the arguments of two warms of one kind are the
     * same (the id of what is warmed, `noCache`) while the record they read differs. Stored only when set, so every
     * other row keeps its bytes; never forwarded to the child. Null = the arguments alone are the input.
     */
    public ?string $warmedCacheIdentity = null;

    /**
     * @param array<string, mixed> $toolArguments
     * @param string[] $requestedSeedSkillNames
     */
    public static function forToolCall(string $toolName, array $toolArguments, array $requestedSeedSkillNames = []): static
    {
        $agentDelegationToolRunTask = new static();
        $agentDelegationToolRunTask->toolName = $toolName;
        $agentDelegationToolRunTask->toolArguments = $toolArguments;
        $agentDelegationToolRunTask->requestedSeedSkillNames = $requestedSeedSkillNames;
        return $agentDelegationToolRunTask;
    }

    /**
     * One item of a tool result's or a purpose step's background work (`{operation, toolName?, toolArguments?,
     * skillNames?}`): an `executeTool` item naming its tool is a plain tool run; every other item keeps
     * its operation ({@see $legacyBackgroundOperationName}) so its seed and its `delegate` item stay today's bytes.
     *
     * @param array<string, mixed> $toolArguments
     * @param string[] $requestedSeedSkillNames
     */
    public static function forLegacyBackgroundOperation(string $operationName, ?string $toolName, array $toolArguments, array $requestedSeedSkillNames): self
    {
        if ($operationName === BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL && $toolName !== null && $toolName !== '') {
            return self::forToolCall($toolName, $toolArguments, $requestedSeedSkillNames);
        }
        $agentDelegationToolRunTask = self::forToolCall($toolName ?? '', $toolArguments, $requestedSeedSkillNames);
        $agentDelegationToolRunTask->legacyBackgroundOperationName = $operationName;
        return $agentDelegationToolRunTask;
    }

    /**
     * The tool an argument item names: its trimmed `toolName` without the namespace of the model's own function list
     * (a model that copies the name from that list writes `functions.<toolName>`; a tool name itself carries no dot);
     * null when the item names no tool.
     *
     * @param array<string, mixed> $argumentItem
     */
    public static function readToolNameFromArgumentItem(array $argumentItem): ?string
    {
        $toolName = $argumentItem['toolName'] ?? null;
        if (!is_string($toolName)) {
            return null;
        }
        $toolName = trim($toolName);
        if (str_starts_with($toolName, self::FUNCTION_LIST_NAMESPACE_PREFIX)) {
            $toolName = trim(substr($toolName, strlen(self::FUNCTION_LIST_NAMESPACE_PREFIX)));
        }
        return $toolName !== '' ? $toolName : null;
    }

    /**
     * The `toolArguments` of an argument item: an object as given, or a JSON string decoded once (a provider that
     * refuses an object schema without `properties` sends the arguments as a string); anything else
     * is no arguments.
     *
     * @param array<string, mixed> $argumentItem
     * @return array<string, mixed>
     */
    public static function readToolArgumentsFromArgumentItem(array $argumentItem): array
    {
        $toolArguments = $argumentItem['toolArguments'] ?? null;
        if (is_array($toolArguments)) {
            return $toolArguments;
        }
        if (is_string($toolArguments) && $toolArguments !== '') {
            $decodedToolArguments = json_decode($toolArguments, true);
            return is_array($decodedToolArguments) ? $decodedToolArguments : [];
        }
        return [];
    }

    /** @return array<string, mixed> `{kind, toolName, toolArguments[, seedSkillNames]}` */
    public function buildStoredTaskArray(): array
    {
        $storedTask = ['kind' => self::KIND_TOOL_RUN, 'toolName' => $this->toolName, 'toolArguments' => $this->toolArguments];
        if ($this->requestedSeedSkillNames !== []) {
            $storedTask['seedSkillNames'] = $this->requestedSeedSkillNames;
        }
        if ($this->legacyBackgroundOperationName !== null) {
            $storedTask['legacyBackgroundOperation'] = $this->legacyBackgroundOperationName;
        }
        if ($this->warmedCacheIdentity !== null) {
            $storedTask['warmedCacheIdentity'] = $this->warmedCacheIdentity;
        }
        return $storedTask;
    }

    /**
     * The same call as `$otherToolRunTask`: the same tool, the same background operation and the same arguments (key
     * order and scalar types aside — a stored id reads back as the number it was).
     */
    public function isSameCallAs(self $otherToolRunTask): bool
    {
        return $this->toolName === $otherToolRunTask->toolName
            && $this->legacyBackgroundOperationName === $otherToolRunTask->legacyBackgroundOperationName
            && $this->toolArguments == $otherToolRunTask->toolArguments;
    }

    /** The same call over the same warmed cache entry ({@see isSameCallAs()} and {@see $warmedCacheIdentity}): the same input. */
    public function hasSameInputAs(self $otherToolRunTask): bool
    {
        return $this->isSameCallAs($otherToolRunTask) && $this->warmedCacheIdentity === $otherToolRunTask->warmedCacheIdentity;
    }

    /**
     * The call a direct route would have made for this tool run (kept 2): the repeat check of the
     * model's tool-run task reads it like a direct call — a completed identical write or a read of this turn is not run
     * again through a sub-agent either.
     */
    public function buildRepeatCheckToolCall(): ToolCall
    {
        $repeatCheckToolCall = new ToolCall();
        $repeatCheckToolCall->toolCallId = 'call_subAgentToolRun';
        $repeatCheckToolCall->name = $this->toolName;
        $repeatCheckToolCall->arguments = $this->toolArguments;
        $repeatCheckToolCall->stampEffect(MCPToolEffectCatalog::getEffectForToolName($this->toolName));
        return $repeatCheckToolCall;
    }

    /**
     * The tool's name and its first scalar argument (`getWebPageAsMarkdown https://…`), cut to a label; a legacy
     * background operation without a tool its label line.
     */
    public function buildDefaultLabel(): string
    {
        if ($this->legacyBackgroundOperationName !== null || $this->toolName === '') {
            return self::cutToLabel($this->buildLabelLine());
        }
        foreach ($this->toolArguments as $toolArgumentValue) {
            if (is_scalar($toolArgumentValue) && !is_bool($toolArgumentValue) && trim((string)$toolArgumentValue) !== '') {
                return self::cutToLabel($this->toolName . ' ' . trim((string)$toolArgumentValue));
            }
        }
        return self::cutToLabel($this->toolName);
    }

    public function buildChildSeedText(): string
    {
        if ($this->legacyBackgroundOperationName !== null) {
            return BackgroundMechanicalOperationPurpose::buildOperationSeedText(
                $this->legacyBackgroundOperationName,
                $this->toolName === '' ? null : $this->toolName,
                $this->toolArguments
            );
        }
        return BackgroundMechanicalOperationPurpose::buildOperationSeedText(
            BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL,
            $this->toolName,
            $this->toolArguments
        );
    }

    /** The label line of the seed — what the parent's `delegate` call and its result slice show as the task. */
    public function buildLabelLine(): string
    {
        return (string)strtok($this->buildChildSeedText(), "\n");
    }

    /** @return array{task: string, skill_names: string[]} */
    public function buildDelegateCallArgumentItem(): array
    {
        return ['task' => $this->buildLabelLine(), 'skill_names' => $this->requestedSeedSkillNames];
    }

    /**
     * `{toolName, toolArguments}` — the model's own tool-run shape; a legacy background operation that is no plain tool
     * call (the CMS detection until it is a tool) names its label line as the task instead, so the row
     * never claims a call the child does not make.
     *
     * @return array<string, mixed>
     */
    public function buildDelegateCallArguments(): array
    {
        if ($this->legacyBackgroundOperationName !== null) {
            return ['task' => $this->buildLabelLine()];
        }
        return ['toolName' => $this->toolName, 'toolArguments' => $this->toolArguments];
    }

    public function getChildPurposeName(): ?string
    {
        return AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION;
    }

    public function requiresModel(): bool
    {
        return false;
    }

    /** @return string[] the requester's pick, empty when it named none */
    public function getRequestedSeedSkillNames(): array
    {
        return $this->requestedSeedSkillNames;
    }

    /**
     * The requester's pick when it named skills; otherwise the first skill of the permitted catalog (catalog order)
     * whose packages provide the tool — none when no permitted skill provides it.
     *
     * @return string[]
     */
    public function buildSeedSkillNames(AISkills $permittedCatalog): array
    {
        if ($this->requestedSeedSkillNames !== []) {
            return $this->requestedSeedSkillNames;
        }
        foreach ($permittedCatalog->getElements() as $permittedSkill) {
            if (in_array($this->toolName, AISkill::getToolNamesForSkillName($permittedSkill->name) ?? [], true)) {
                return [$permittedSkill->name];
            }
        }
        return [];
    }
}
