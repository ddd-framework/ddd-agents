<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One answer a person may give to an approval request (a button in Slack, a button in the admin list).
 */
class AgentConnectionApprovalOption extends ValueObject
{
    /** @var string {@see AgentConnectionApprovalRequest::OPTION_APPROVE} or OPTION_REJECT */
    public string $key = '';

    public string $label = '';

    public static function create(string $key, string $label): static
    {
        $option = new static();
        $option->key = $key;
        $option->label = $label;
        return $option;
    }
}
