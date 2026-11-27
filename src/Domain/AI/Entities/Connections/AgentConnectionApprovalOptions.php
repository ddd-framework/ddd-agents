<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The answers of one approval request.
 *
 * @property AgentConnectionApprovalOption[] $elements
 * @method AgentConnectionApprovalOption[] getElements()
 */
class AgentConnectionApprovalOptions extends ObjectSet
{
    public static function approveOrReject(): static
    {
        $options = new static();
        $addedElement = AgentConnectionApprovalOption::create(AgentConnectionApprovalRequest::OPTION_APPROVE, 'Approve');
        $options->add($addedElement);
        $addedElement = AgentConnectionApprovalOption::create(AgentConnectionApprovalRequest::OPTION_REJECT, 'Reject');
        $options->add($addedElement);
        return $options;
    }

    public function hasKey(string $key): bool
    {
        foreach ($this->getElements() as $option) {
            if ($option->key === $key) {
                return true;
            }
        }
        return false;
    }
}
