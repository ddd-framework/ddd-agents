<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItemDeclaration;

/**
 * The item class of an application whose guided setup run declares fields of its own on its plan items: two
 * connections an item may wait for, an offer the application parks when the setup ends, the application step an item
 * completes and the item that triggers the interface-language confirmation. The characterization tables were recorded
 * with such an application; the tests register this class in the module class's place and read the tables with it.
 */
class TaskPlanItemOfAnApplicationWithAGuidedSetup extends TaskPlanItem
{
    public const string CONNECTED_CHANNEL_GOOGLE = 'google';

    public const string CONNECTED_CHANNEL_SOCIAL = 'social';

    public const array CONNECTED_CHANNEL_LABELS = [
        self::CONNECTED_CHANNEL_GOOGLE => 'the Google Business Profile',
        self::CONNECTED_CHANNEL_SOCIAL => 'a social channel',
    ];

    public const string DEFERRED_AT_SETUP_END_STATUS_NOTE =
        'Deferred when the setup ended at the handover — this offer was not made in the setup; available from the dashboard.';

    /** @var bool Seeded on an offer: the application parks it as deferred when the setup ends with the item open. */
    public bool $deferrableAtSetupEnd = false;

    /** @var bool The application parked this item at the setup's end. */
    public bool $deferredAtSetupEnd = false;

    /** @var string|null The application step this item's done completes. */
    public ?string $completesSetupStepName = null;

    /** @var bool This item's done triggers the application's interface-language confirmation. */
    public bool $confirmsInterfaceLanguage = false;

    public function getSeededDeclarationKinds(): array
    {
        return parent::getSeededDeclarationKinds() + [
            'deferrableAtSetupEnd' => TaskPlanItemDeclaration::KIND_BOOL,
            'completesSetupStepName' => TaskPlanItemDeclaration::KIND_TEXT,
            'confirmsInterfaceLanguage' => TaskPlanItemDeclaration::KIND_BOOL,
        ];
    }

    public function getNotConnectedChannelAdvice(): string
    {
        return 'An unclicked widget stays OPEN (awaiting the owner; the platform parks it at the setup\'s end); a deferral or '
            . 'decline in the owner\'s WORDS is `blocked` with requiresUserAction after addOwnerStatement. '
            . 'The platform ticks it on the CONNECTED signal.';
    }

    public function getFixedEnglishBlockedStatusNote(): ?string
    {
        return $this->deferredAtSetupEnd ? self::DEFERRED_AT_SETUP_END_STATUS_NOTE : null;
    }

    public function getBlockedMarkOnJudgeLine(): string
    {
        return $this->deferredAtSetupEnd ? ' (deferred at setup end)' : '';
    }

    public function getJudgeLegendOfBlockedMark(): string
    {
        return '(deferred at setup end) = a parked unanswered offer; ';
    }

    public function isClosedByThePlatformFromTheRecord(): bool
    {
        return parent::isClosedByThePlatformFromTheRecord() || $this->completesSetupStepName !== null;
    }

    public function carriesRecordEvidenceGate(): bool
    {
        return parent::carriesRecordEvidenceGate() || $this->completesSetupStepName !== null;
    }

    public function carriesEvidenceGate(): bool
    {
        return parent::carriesEvidenceGate() || $this->deferrableAtSetupEnd
            || $this->completesSetupStepName !== null || $this->confirmsInterfaceLanguage;
    }

    public function toRepositoryFields(): array
    {
        $repositoryFields = self::insertRepositoryFieldsAfter(parent::toRepositoryFields(), 'evidenceMessageIndex', [
            'deferrableAtSetupEnd' => $this->deferrableAtSetupEnd,
            'deferredAtSetupEnd' => $this->deferredAtSetupEnd,
        ]);
        return self::insertRepositoryFieldsAfter($repositoryFields, 'triggersSupervisorMilestoneCheckpoint', [
            'completesSetupStepName' => $this->completesSetupStepName,
            'confirmsInterfaceLanguage' => $this->confirmsInterfaceLanguage,
        ]);
    }

    public function readRepositoryFields(array $itemFields): void
    {
        parent::readRepositoryFields($itemFields);
        $this->deferredAtSetupEnd = (bool)($itemFields['deferredAtSetupEnd'] ?? false);
    }
}
