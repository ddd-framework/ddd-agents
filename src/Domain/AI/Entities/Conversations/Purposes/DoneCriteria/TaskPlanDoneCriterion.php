<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One done criterion of a purpose's seeded plan: the seed's `doneCriterion` key it decides, the tools
 * whose completed call can change its verdict, and the verdict over {@see TaskPlanDoneCriterionInputs}. A purpose
 * registers its criteria in {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::DONE_CRITERION_CLASSES};
 * a key without a registered class is NO gate. A domain names its own base that narrows the inputs type and holds no
 * logic (e.g. `final resolve()` → `resolveForSetup(SetupDoneCriterionInputs)`).
 */
abstract class TaskPlanDoneCriterion extends ValueObject
{
    /** The seed's `doneCriterion` key this class decides (purpose vocabulary, unique within the purpose). */
    public const string KEY = '';

    /**
     * @var string[] Keys this criterion decided under before a rename — a plan stored then carries them. The registry
     * resolves such a key to this criterion ({@see TaskPlanDoneCriteria::forKey()}) and the run start renames it in the
     * stored plan ({@see TaskPlanDoneCriteria::getCurrentKeysByLegacyKey()}), so the item keeps its gate.
     */
    public const array LEGACY_KEYS = [];

    /** @var string[] Tools whose COMPLETED call can change the verdict — the post-write re-sweep reads it. */
    public const array CHANGED_BY_TOOLS = [];

    /**
     * @var bool The verdict can change when an owner turn ENDS, without any tool (a rule on the owner's nod in an earlier,
     * finished turn): the turn opened by the owner's next typed message re-reads it, since no write may follow.
     */
    public const bool RESOLVES_ONCE_AN_OWNER_TURN_ENDS = false;

    /**
     * @var string[] The service-backed facts this criterion reads through its inputs. The inputs read such a fact LAZILY:
     * at the criterion's first ask within the call, once, and keep it for the rest of the call — a call that never asks
     * the criterion never reads the fact.
     */
    public const array GATHERED_FACTS = [];

    /**
     * The record's verdict; null = undecided (the leaf stays open). Reads its inputs only: no service call of its own, no
     * clock — a service-backed fact comes from an accessor of the inputs ({@see GATHERED_FACTS}). Called lazily, at the
     * moment a tick, a sweep or a record note needs this key.
     */
    abstract public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict;

    public function getKey(): string
    {
        return static::KEY;
    }

    /** @return string[] {@see LEGACY_KEYS} */
    public function getLegacyKeys(): array
    {
        return static::LEGACY_KEYS;
    }

    public function isChangedByTool(string $toolName): bool
    {
        return in_array($toolName, static::CHANGED_BY_TOOLS, true);
    }

    /** Identity in {@see TaskPlanDoneCriteria} is the KEY — two classes deciding one key are one criterion too many. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyForDoneCriterionKey(static::KEY);
    }

    public static function uniqueKeyForDoneCriterionKey(string $doneCriterionKey): string
    {
        return self::class . '_' . $doneCriterionKey;
    }

    protected function done(string $statusNote): TaskPlanDoneCriterionVerdict
    {
        return TaskPlanDoneCriterionVerdict::done($statusNote);
    }

    protected function doneOverridingPlatformBlock(string $statusNote): TaskPlanDoneCriterionVerdict
    {
        return TaskPlanDoneCriterionVerdict::doneOverridingPlatformBlock($statusNote);
    }

    protected function notApplicable(string $statusNote): TaskPlanDoneCriterionVerdict
    {
        return TaskPlanDoneCriterionVerdict::notApplicable($statusNote);
    }

    protected function blockedByOwnerStatement(string $statusNote): TaskPlanDoneCriterionVerdict
    {
        return TaskPlanDoneCriterionVerdict::blockedByOwnerStatement($statusNote);
    }

    protected function stillOpen(string $reason): TaskPlanDoneCriterionVerdict
    {
        return TaskPlanDoneCriterionVerdict::stillOpen($reason);
    }
}
