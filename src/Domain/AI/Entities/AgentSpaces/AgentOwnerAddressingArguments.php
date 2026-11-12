<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The two inputs of the owner addressing for ONE conversation: the register the settings of its space ask for, and
 * whether the space runs the agent with the informal register as its default. The application answers them for its
 * space ({@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::resolveOwnerAddressingArguments()}); the chat
 * prompt, a voice briefing and an addressing tool read the same answer, and the prompt cache fingerprints it.
 *
 * @phpstan-consistent-constructor
 */
class AgentOwnerAddressingArguments extends ValueObject
{
    /** @var string|null The register the settings of the space ask for; null when they name none */
    public ?string $agentSettingWritingStyle = null;

    /** @var bool Whether the space runs the agent with the informal register as its default */
    public bool $agentSpaceDefaultsToInformal = false;

    /**
     * @param string|null $agentSettingWritingStyle
     * @param bool $agentSpaceDefaultsToInformal
     * @return static
     */
    public static function forWritingStyleAndInformalDefault(?string $agentSettingWritingStyle, bool $agentSpaceDefaultsToInformal): static
    {
        $agentOwnerAddressingArguments = new static();
        $agentOwnerAddressingArguments->agentSettingWritingStyle = $agentSettingWritingStyle;
        $agentOwnerAddressingArguments->agentSpaceDefaultsToInformal = $agentSpaceDefaultsToInformal;
        return $agentOwnerAddressingArguments;
    }

    /**
     * The two values as the prompt cache fingerprints them.
     *
     * @return array{agentSettingWritingStyle: string|null, agentSpaceDefaultsToInformal: bool}
     */
    public function toFingerprintFields(): array
    {
        return [
            'agentSettingWritingStyle' => $this->agentSettingWritingStyle,
            'agentSpaceDefaultsToInformal' => $this->agentSpaceDefaultsToInformal,
        ];
    }
}
