<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\Base\Entities\ObjectSet;
use LogicException;
use Throwable;

/**
 * The done criteria of ONE purpose, keyed by {@see TaskPlanDoneCriterion::KEY} — what
 * {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::getDoneCriteria()} returns and the
 * pre-resolution walk ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems::applyPreResolutions()}) asks
 * per leaf. A key without a class answers null, and every caller treats null as NO gate. A registry built for a purpose
 * ({@see forPurpose()}) asks that purpose for every verdict, so a rule of the purpose that applies before its criteria
 * ({@see AIConversationPurpose::resolveVerdictWithPurposeRules()}) runs wherever a verdict is asked.
 *
 * @property TaskPlanDoneCriterion[] $elements
 * @method TaskPlanDoneCriterion[] getElements()
 * @method TaskPlanDoneCriterion|null first()
 * @phpstan-consistent-constructor
 */
class TaskPlanDoneCriteria extends ObjectSet
{
    /** The purpose whose rules apply before each criterion; null = the criterion alone decides (a registry of bare classes). */
    public ?AIConversationPurpose $aIConversationPurpose = null;

    /**
     * One instance per class, in the given order. A purpose that keeps its own registry subclass builds it
     * through this factory too (`static`). A class that is no {@see TaskPlanDoneCriterion}, or a second class
     * deciding a key already registered, throws — the registry test catches it at build time.
     *
     * @param string ...$criterionClasses the criterion class names (each a TaskPlanDoneCriterion subclass)
     */
    public static function fromClasses(string ...$criterionClasses): static
    {
        $taskPlanDoneCriteria = new static();
        foreach ($criterionClasses as $criterionClass) {
            if (!is_a($criterionClass, TaskPlanDoneCriterion::class, true)) {
                throw new LogicException("$criterionClass is no " . TaskPlanDoneCriterion::class);
            }
            /** @var TaskPlanDoneCriterion $taskPlanDoneCriterion */
            $taskPlanDoneCriterion = new $criterionClass();
            foreach ([$taskPlanDoneCriterion->getKey(), ...$taskPlanDoneCriterion->getLegacyKeys()] as $decidedKey) {
                if ($taskPlanDoneCriteria->forKey($decidedKey) !== null) {
                    throw new LogicException("the done-criterion key '$decidedKey' is registered twice ($criterionClass)");
                }
            }
            $taskPlanDoneCriteria->add($taskPlanDoneCriterion);
        }
        return $taskPlanDoneCriteria;
    }

    /**
     * The registry of `$aIConversationPurpose`: one instance per class of its {@see AIConversationPurpose::DONE_CRITERION_CLASSES},
     * and the purpose, whose rules apply before each criterion ({@see resolveVerdictOfCriterion()}).
     */
    public static function forPurpose(AIConversationPurpose $aIConversationPurpose): static
    {
        $taskPlanDoneCriteria = static::fromClasses(...$aIConversationPurpose::DONE_CRITERION_CLASSES);
        $taskPlanDoneCriteria->aIConversationPurpose = $aIConversationPurpose;
        return $taskPlanDoneCriteria;
    }

    /**
     * The criterion deciding `$doneCriterionKey` — its current key or one of its {@see TaskPlanDoneCriterion::LEGACY_KEYS}
     * (a plan stored before the rename) — null when no class decides it (= no gate).
     */
    public function forKey(string $doneCriterionKey): ?TaskPlanDoneCriterion
    {
        $taskPlanDoneCriterion = $this->getByUniqueKey(TaskPlanDoneCriterion::uniqueKeyForDoneCriterionKey($doneCriterionKey));
        if ($taskPlanDoneCriterion instanceof TaskPlanDoneCriterion) {
            return $taskPlanDoneCriterion;
        }
        foreach ($this->getElements() as $registeredDoneCriterion) {
            if (in_array($doneCriterionKey, $registeredDoneCriterion->getLegacyKeys(), true)) {
                return $registeredDoneCriterion;
            }
        }
        return null;
    }

    /**
     * Every legacy key of the registered criteria with the current key that replaced it — what the run start renames
     * in a stored plan ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems::renameLegacyDoneCriterionKeys()}).
     *
     * @return array<string, string> current key by legacy key
     */
    public function getCurrentKeysByLegacyKey(): array
    {
        $currentKeysByLegacyKey = [];
        foreach ($this->getElements() as $taskPlanDoneCriterion) {
            foreach ($taskPlanDoneCriterion->getLegacyKeys() as $legacyKey) {
                $currentKeysByLegacyKey[$legacyKey] = $taskPlanDoneCriterion->getKey();
            }
        }
        return $currentKeysByLegacyKey;
    }

    /** @return string[] the registered keys, in registration order */
    public function getKeys(): array
    {
        return array_values(array_map(static fn (TaskPlanDoneCriterion $taskPlanDoneCriterion): string => $taskPlanDoneCriterion->getKey(), $this->getElements()));
    }

    /**
     * The keys a COMPLETED call of `$toolName` can have changed — what the post-write re-sweep re-reads: the keys whose
     * {@see TaskPlanDoneCriterion::CHANGED_BY_TOOLS} names the tool; `[]` when the purpose lists the tool as known and
     * changing no criterion; null when neither applies (unknown tool — every open criterion leaf is re-read).
     *
     * @param string[] $toolsChangingNoDoneCriterion
     * @return string[]|null
     */
    public function getKeysChangedByTool(string $toolName, array $toolsChangingNoDoneCriterion): ?array
    {
        $keysChangedByTool = [];
        foreach ($this->getElements() as $taskPlanDoneCriterion) {
            if ($taskPlanDoneCriterion->isChangedByTool($toolName)) {
                $keysChangedByTool[] = $taskPlanDoneCriterion->getKey();
            }
        }
        if ($keysChangedByTool !== []) {
            return $keysChangedByTool;
        }
        return in_array($toolName, $toolsChangingNoDoneCriterion, true) ? [] : null;
    }

    /**
     * The ONE lazy entry every caller uses: the verdict of the criterion deciding `$doneCriterionKey` over the inputs.
     * A key without a class answers null (no gate); a criterion that throws answers null for that key. The purpose's
     * rules apply first ({@see resolveVerdictOfCriterion()}).
     */
    public function resolveKey(string $doneCriterionKey, TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        $taskPlanDoneCriterion = $this->forKey($doneCriterionKey);
        if ($taskPlanDoneCriterion === null) {
            return null;
        }
        try {
            return $this->resolveVerdictOfCriterion($taskPlanDoneCriterion, $taskPlanDoneCriterionInputs);
        } catch (Throwable) {
            return null;
        }
    }

    /** The verdict of one registered criterion: through the purpose's rules when the registry has a purpose, else the criterion's own. */
    protected function resolveVerdictOfCriterion(TaskPlanDoneCriterion $taskPlanDoneCriterion, TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        if ($this->aIConversationPurpose === null) {
            return $taskPlanDoneCriterion->resolve($taskPlanDoneCriterionInputs);
        }
        return $this->aIConversationPurpose->resolveVerdictWithPurposeRules($taskPlanDoneCriterion, $taskPlanDoneCriterionInputs);
    }
}
