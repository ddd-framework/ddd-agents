<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentSpaceContextFields;

/**
 * The context fields of an application whose builder writes `Label: value; ` parts: it declares three fields and reads
 * them by their labels.
 */
class AgentSpaceContextFieldsOfAnApplicationWithLabels extends AgentSpaceContextFields
{
    public const string FIELD_NAME = 'Name';
    public const string FIELD_CATEGORIES = 'Categories';
    public const string FIELD_WEBSITE = 'Website';

    protected const array FIELDS_IN_ORDER = [self::FIELD_NAME, self::FIELD_CATEGORIES, self::FIELD_WEBSITE];

    protected function readFieldsOfText(string $agentSpaceContextText): void
    {
        foreach (explode('; ', $agentSpaceContextText) as $labelledPart) {
            $labelEnd = strpos($labelledPart, ': ');
            if ($labelEnd === false) {
                continue;
            }
            $field = substr($labelledPart, 0, $labelEnd);
            if (in_array($field, self::FIELDS_IN_ORDER, true)) {
                $this->valuesByField[$field] = trim(substr($labelledPart, $labelEnd + 2));
            }
        }
    }
}
