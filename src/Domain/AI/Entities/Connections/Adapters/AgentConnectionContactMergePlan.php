<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The plan of one merge of two people of My Contacts in one connection, shown in full under one approval: the
 * survivor's update carrying every donor field, the labels moved, the photo moved when the survivor has none, the host
 * links retargeted, then the donor deleted. `lostLines` names what cannot be kept.
 */
class AgentConnectionContactMergePlan extends ValueObject
{
    /** @var AgentConnectionContact|null The survivor after the merge, with the etag of its read */
    public ?AgentConnectionContact $survivor = null;
    public string $donorContactId = '';

    /** @var string|null The donor's etag as read for the plan */
    public ?string $donorEtag = null;
    public string $donorName = '';
    /** @var string[] The fields the survivor's update changes (names, emailAddresses, phoneNumbers, organizations, biographies) */
    public array $fieldNames = [];
    /** @var string[] Labels of the donor the survivor joins */
    public array $labelsToAdd = [];
    /** @var string[] Single-value conflicts: which value is kept */
    public array $conflictLines = [];
    /** @var string[] What the merge cannot keep */
    public array $lostLines = [];

    public function describeForApproval(): string
    {
        return implode("\n", array_filter([
            "Merge \"{$this->donorName}\" ({$this->donorContactId}) into \"{$this->survivor?->name}\" ({$this->survivor?->contactId}); the donor is deleted afterwards.",
            'Survivor after the merge:',
            $this->survivor?->describeForApproval(),
            $this->fieldNames !== [] ? 'Changed fields: ' . implode(', ', $this->fieldNames) : 'No field changes.',
            $this->labelsToAdd !== [] ? 'Labels added to the survivor: ' . implode(', ', $this->labelsToAdd) : null,
            $this->conflictLines !== [] ? "Conflicts (kept value):\n- " . implode("\n- ", $this->conflictLines) : null,
            $this->lostLines !== [] ? "Lost:\n- " . implode("\n- ", $this->lostLines) : 'Nothing is lost.',
        ]));
    }
}
