<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one reach of a contacts connection may see, built from the boundaries of the
 * connection and of the release ({@see \DDD\Domain\AI\Services\Connections\AgentConnectionAccess}):
 * - the enabled sources (boundary kind CONTACT_SOURCE; none = My Contacts and the directory),
 * - the label allowlist of My Contacts (boundary kind CONTACT_LABEL, contactGroups/… resource names; none = all).
 * With an allowlist the adapter never uses the provider's search (it cannot filter by label): it lists the allowed
 * labels' members and filters locally, and every read checks the person's labels.
 */
class AgentConnectionContactsScope extends ValueObject
{
    public const string BOUNDARY_SOURCE = AgentConnectionScopeBoundary::KIND_CONTACT_SOURCE;
    public const string BOUNDARY_LABEL = AgentConnectionScopeBoundary::KIND_CONTACT_LABEL;

    /** @var string[] Sources on when a connection names none (Other contacts off unless named) */
    public const array DEFAULT_SOURCES = [AgentConnectionContact::SOURCE_CONTACTS, AgentConnectionContact::SOURCE_DIRECTORY];

    /** @var string[] */
    public array $allowedSources = self::DEFAULT_SOURCES;

    /** @var string[]|null null = every label */
    public ?array $allowedLabels = null;

    /**
     * @param string[]|null $allowedSourceValues
     * @param string[]|null $allowedLabelValues
     */
    public static function fromBoundaryValues(?array $allowedSourceValues, ?array $allowedLabelValues): static
    {
        $contactsScope = static::newInstance();
        if ($allowedSourceValues !== null) {
            $contactsScope->allowedSources = array_values(array_intersect(AgentConnectionContact::SOURCES, array_map('strtoupper', $allowedSourceValues)));
        }
        $contactsScope->allowedLabels = $allowedLabelValues !== null ? array_values($allowedLabelValues) : null;
        return $contactsScope;
    }

    public function isSourceAllowed(string $source): bool
    {
        return in_array($source, $this->allowedSources, true);
    }

    public function hasLabelAllowlist(): bool
    {
        return $this->allowedLabels !== null;
    }

    public function isLabelAllowed(string $groupId): bool
    {
        return $this->allowedLabels === null || in_array($groupId, $this->allowedLabels, true);
    }

    /** A person of My Contacts passes only with at least one allowed label; Other contacts and the directory have none */
    public function allowsContact(AgentConnectionContact $contact): bool
    {
        if (!$this->isSourceAllowed($contact->source)) {
            return false;
        }
        if ($contact->source !== AgentConnectionContact::SOURCE_CONTACTS || $this->allowedLabels === null) {
            return true;
        }
        return array_intersect($contact->labels, $this->allowedLabels) !== [];
    }
}
