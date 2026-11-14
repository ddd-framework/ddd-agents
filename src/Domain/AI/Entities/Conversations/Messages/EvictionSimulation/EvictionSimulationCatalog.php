<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The capabilities the eviction simulator can load, one per name, in catalog order (meta tools, then each skill followed by
 * its tools). The built-in catalog uses sizes of real skill bodies (1.2k–2.4k tokens) and tool schemas (0.2k–1.1k).
 *
 * @phpstan-consistent-constructor
 */
class EvictionSimulationCatalog extends ValueObject
{
    /** @var EvictionSimulationCapabilities The capabilities, one per name, in catalog order */
    public EvictionSimulationCapabilities $capabilities;

    public function __construct()
    {
        parent::__construct();
        $this->capabilities = new EvictionSimulationCapabilities();
    }

    /**
     * Adds a capability; one of the same name already in the catalog is replaced in its place.
     *
     * @param EvictionSimulationCapability $evictionSimulationCapability
     * @return void
     */
    public function addCapability(EvictionSimulationCapability $evictionSimulationCapability): void
    {
        $this->capabilities->addCapability($evictionSimulationCapability);
    }

    /**
     * @param string $capabilityName
     * @return EvictionSimulationCapability|null
     */
    public function getByName(string $capabilityName): ?EvictionSimulationCapability
    {
        return $this->capabilities->getByName($capabilityName);
    }

    /**
     * @param string $skillName
     * @return EvictionSimulationCapabilities the tools of the skill, in catalog order
     */
    public function getToolsOfSkill(string $skillName): EvictionSimulationCapabilities
    {
        return $this->capabilities->getToolsOfSkill($skillName);
    }

    /** @return string[] the capability names in catalog order */
    public function getCapabilityNames(): array
    {
        return $this->capabilities->getNames();
    }

    /**
     * The built-in catalog: three meta tools and five skills with their tools.
     *
     * @return static
     */
    public static function buildBuiltIn(): static
    {
        $evictionSimulationCatalog = new static();
        $catalogEntries = [
            ['load_skills', LoadedCapability::TYPE_TOOL, 250, true, null],
            ['load_tool', LoadedCapability::TYPE_TOOL, 200, true, null],
            ['delegate', LoadedCapability::TYPE_TOOL, 350, true, null],
            ['Documents.Search', LoadedCapability::TYPE_SKILL, 2400, false, null],
            ['searchDocuments', LoadedCapability::TYPE_TOOL, 420, false, 'Documents.Search'],
            ['compareDocuments', LoadedCapability::TYPE_TOOL, 480, false, 'Documents.Search'],
            ['listFolders', LoadedCapability::TYPE_TOOL, 300, false, 'Documents.Search'],
            ['listTags', LoadedCapability::TYPE_TOOL, 380, false, 'Documents.Search'],
            ['Tickets.Overview', LoadedCapability::TYPE_SKILL, 1800, false, null],
            ['getTicketSummary', LoadedCapability::TYPE_TOOL, 900, false, 'Tickets.Overview'],
            ['listTickets', LoadedCapability::TYPE_TOOL, 1100, false, 'Tickets.Overview'],
            ['getTicketTopics', LoadedCapability::TYPE_TOOL, 700, false, 'Tickets.Overview'],
            ['Calendar.Events', LoadedCapability::TYPE_SKILL, 1600, false, null],
            ['getEvents', LoadedCapability::TYPE_TOOL, 850, false, 'Calendar.Events'],
            ['getCalendars', LoadedCapability::TYPE_TOOL, 600, false, 'Calendar.Events'],
            ['Notes.Drafts', LoadedCapability::TYPE_SKILL, 1400, false, null],
            ['getNotes', LoadedCapability::TYPE_TOOL, 500, false, 'Notes.Drafts'],
            ['createNote', LoadedCapability::TYPE_TOOL, 650, false, 'Notes.Drafts'],
            ['Assistant.Settings', LoadedCapability::TYPE_SKILL, 1200, false, null],
            ['getSettings', LoadedCapability::TYPE_TOOL, 400, false, 'Assistant.Settings'],
            ['updateSettings', LoadedCapability::TYPE_TOOL, 450, false, 'Assistant.Settings'],
        ];
        foreach ($catalogEntries as [$capabilityName, $capabilityType, $residentTokens, $isAlwaysLoaded, $parentSkillName]) {
            $evictionSimulationCatalog->addCapability(
                EvictionSimulationCapability::forEntry($capabilityName, $capabilityType, $residentTokens, $isAlwaysLoaded, $parentSkillName)
            );
        }
        return $evictionSimulationCatalog;
    }
}
