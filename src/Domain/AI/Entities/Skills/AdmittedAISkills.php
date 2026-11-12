<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a NON-model preload may load: the admitted names and, for every refused name, why —
 * built by {@see \DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog::buildAdmissionFor()} over a resolved
 * request. A name the offer policy dropped carries the policy step that dropped it ({@see
 * \DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog::getRejectionStep()}), else one of the reasons below.
 * Reads and writes nothing; the promotion of the admitted names is the admission service's.
 */
class AdmittedAISkills extends ValueObject
{
    /** No skill of that name exists. */
    public const string REJECTED_UNKNOWN = 'unknown';

    /** Loaded on the anchor already. */
    public const string REJECTED_ALREADY_LOADED = 'alreadyLoaded';

    /** Not in the offered catalog, and no offer step dropped it (a sub-skill whose root is not loaded). */
    public const string REJECTED_NOT_OFFERED = 'notOffered';

    /** A sub-skill named by a source other than a plan item or a decision-model follow-up. */
    public const string REJECTED_SUB_SKILL = 'subSkill';

    /** Needs vision on a model known to be blind. */
    public const string REJECTED_VISION_LOCKED = 'visionLocked';

    /** @var string[] The admitted names, request order. */
    public array $admittedSkillNames = [];

    /** @var array<string, string> Refused name → why (a REJECTED_* reason or the offer step that dropped it). */
    public array $rejectionReasonsBySkillName = [];

    /** @var int The admission passes merged into this one ({@see mergePass()}); 1 for a single admission. */
    public int $passCount = 1;

    /** The refusal reason of `$skillName`, null when it was admitted or never asked about. */
    public function getRejectionReason(string $skillName): ?string
    {
        return $this->rejectionReasonsBySkillName[$skillName] ?? null;
    }

    /**
     * Merges one pass of a multi-pass admission (the plan-item preload): its admitted names join the ones admitted so
     * far, its refusals replace the earlier ones (the last pass's stand), and the pass is counted.
     */
    public function mergePass(self $passAdmission): void
    {
        $this->admittedSkillNames = array_values(array_unique(array_merge($this->admittedSkillNames, $passAdmission->admittedSkillNames)));
        $this->rejectionReasonsBySkillName = $passAdmission->rejectionReasonsBySkillName;
        $this->passCount++;
    }

    /**
     * What the next pass of a plan-item preload asks: when this pass admitted a root WITH
     * sub-skills, the names it refused as not offered — the sub-skills that root now unlocks; otherwise nothing, and the
     * passes end.
     *
     * @return string[]
     */
    public function getSkillNamesToRetryUnderAdmittedRoots(RequestedAISkills $requestedAISkills): array
    {
        foreach ($this->admittedSkillNames as $admittedSkillName) {
            if (($requestedAISkills->getSkill($admittedSkillName)->subSkillNames ?? []) !== []) {
                return array_keys(array_filter(
                    $this->rejectionReasonsBySkillName,
                    static fn (string $rejectionReason): bool => $rejectionReason === self::REJECTED_NOT_OFFERED
                ));
            }
        }
        return [];
    }
}
