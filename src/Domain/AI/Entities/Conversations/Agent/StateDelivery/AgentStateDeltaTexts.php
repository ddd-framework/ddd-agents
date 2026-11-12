<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;

/**
 * The texts of the state notes: one `[SIGNAL:<kind>] …` user entry per changed value, worded as a
 * change against what the instructions say; a note that asks for no action ends with {@see BACKGROUND_SUFFIX}. The
 * notes about the space's context text and its time zone name the line of the instructions, their kind and what the
 * text describes with the application's words ({@see AgentApplicationWording}). Pure.
 */
class AgentStateDeltaTexts
{
    /** Ends every note that only informs. */
    public const string BACKGROUND_SUFFIX = 'Background — no reply, no announcement.';

    public const string OWNER_ADDRESSING_LEAD = '[SIGNAL:owner-addressing] The way you address the owner changed. From now on this replaces the addressing rule of your instructions:';

    public const string OWNER_ADDRESSING_NONE = '[SIGNAL:owner-addressing] The addressing rule of your instructions no longer applies; address the owner neutrally until told otherwise.';

    public const string STANDING_INSTRUCTIONS_LEAD = '[SIGNAL:standing-instructions] The owner\'s standing instructions changed. From now on they read as follows and replace the standing instructions of your instructions:';

    public const string STANDING_INSTRUCTIONS_NONE = '[SIGNAL:standing-instructions] The owner\'s standing instructions changed: none apply any more — the ones in your instructions are withdrawn.';

    public const string PLAN_OF_THE_PRODUCT_LEAD = '[SIGNAL:plan-of-the-product] The customer\'s plan, capabilities or rights changed. These blocks replace the ones of your instructions:';

    public const string SITUATION_ALL_CONFIRMED = '[SIGNAL:situation] Every value of the situation is confirmed now (stated by the owner or fixed).';

    /** The first line of the note that reports a changed context text of the space. */
    public static function buildAgentSpaceContextLead(): string
    {
        $agentApplicationWording = AgentApplicationWording::current();
        return '[SIGNAL:' . $agentApplicationWording->agentSpaceContextStateKind . '] The ' . $agentApplicationWording->agentSpaceContextSubjectName
            . ' record changed since the "' . $agentApplicationWording->agentSpaceContextLabel . '" line of your instructions was written — the values now:';
    }

    /** The note of a context text that went back to the values of the instructions' line. */
    public static function buildAgentSpaceContextAsInInstructionsText(): string
    {
        $agentApplicationWording = AgentApplicationWording::current();
        return '[SIGNAL:' . $agentApplicationWording->agentSpaceContextStateKind . '] The ' . $agentApplicationWording->agentSpaceContextSubjectName
            . ' record is back to the values of the "' . $agentApplicationWording->agentSpaceContextLabel . '" line of your instructions — the earlier '
            . $agentApplicationWording->agentSpaceContextStateKind . ' notes no longer apply.';
    }

    /**
     * The note about the context text of the space: the fields changed between `$agentSpaceContextBefore` (the epoch
     * record's text, the line of the instructions) and now ({@see AgentSpaceContextFields}, read by the application's
     * class), the back-to-the-instructions note when the two are equal, or the whole new text when the fields cannot
     * name the change.
     */
    public static function buildAgentSpaceContextText(?string $agentSpaceContextBefore, ?string $agentSpaceContextAfter): string
    {
        if ($agentSpaceContextBefore !== null && $agentSpaceContextBefore === $agentSpaceContextAfter) {
            return self::buildAgentSpaceContextAsInInstructionsText() . "\n" . self::BACKGROUND_SUFFIX;
        }
        $changeLines = AgentSpaceContextFields::parse($agentSpaceContextAfter)->describeChangesSince(AgentSpaceContextFields::parse($agentSpaceContextBefore));
        if ($changeLines === []) {
            $changeLines = [
                trim((string)$agentSpaceContextAfter) !== ''
                    ? trim((string)$agentSpaceContextAfter)
                    : 'no ' . mb_strtolower(AgentApplicationWording::current()->agentSpaceContextLabel) . ' any more',
            ];
        }
        return self::buildAgentSpaceContextLead() . "\n" . implode("\n", array_map(static fn (string $changeLine): string => '- ' . $changeLine, $changeLines))
            . "\n" . self::BACKGROUND_SUFFIX;
    }

    /** The owner-addressing note — the one note that IS an instruction (no background suffix). */
    public static function buildOwnerAddressingText(?string $ownerAddressingAfter): string
    {
        $ownerAddressingAfter = trim((string)$ownerAddressingAfter);
        return $ownerAddressingAfter === '' ? self::OWNER_ADDRESSING_NONE : self::OWNER_ADDRESSING_LEAD . "\n" . $ownerAddressingAfter;
    }

    /**
     * The time-zone note: a zone change names both zones and the brackets before the note; a newly unconfirmed zone
     * carries the unconfirmed note itself; a confirmation says so.
     */
    public static function buildTimeZoneText(?string $zoneBefore, ?string $zoneAfter, ?string $unconfirmedNoteBefore, ?string $unconfirmedNoteAfter): string
    {
        $noteParts = [];
        if ($zoneBefore !== $zoneAfter && $zoneAfter !== null) {
            $noteParts[] = '[SIGNAL:time-zone] The ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ' time zone is now ' . $zoneAfter
                . ($zoneBefore !== null
                    ? ' (was ' . $zoneBefore . '); the times in the brackets before this note are in ' . $zoneBefore . ', the later ones in ' . $zoneAfter . '.'
                    : '.');
        }
        if ($unconfirmedNoteAfter !== null && $unconfirmedNoteAfter !== $unconfirmedNoteBefore) {
            $noteParts[] = $unconfirmedNoteAfter;
        } elseif ($unconfirmedNoteAfter === null && $unconfirmedNoteBefore !== null && $zoneAfter !== null) {
            $noteParts[] = '[SIGNAL:time-zone] ' . $zoneAfter . ' is confirmed now — no need to ask for the time zone.';
        }
        return implode("\n", $noteParts);
    }

    /** The standing-instructions note: the whole new block. */
    public static function buildStandingInstructionsText(?string $memoryDirectivesAfter): string
    {
        $memoryDirectivesAfter = trim((string)$memoryDirectivesAfter);
        return $memoryDirectivesAfter === '' ? self::STANDING_INSTRUCTIONS_NONE : self::STANDING_INSTRUCTIONS_LEAD . "\n\n" . $memoryDirectivesAfter;
    }

    /**
     * The plan-of-the-product note: the three blocks as the system content heads them (the plan & capabilities, the
     * AI budget, the restricted capabilities), each that changed.
     *
     * @param array<string, string|null> $productBlocksBefore
     * @param array<string, string|null> $productBlocksAfter
     */
    public static function buildPlanOfTheProductText(array $productBlocksBefore, array $productBlocksAfter): string
    {
        $headingsByBlock = [
            'productCapabilities' => "# The customer's plan & capabilities",
            'aiBudgetAwareness' => '# AI budget',
            'restrictedCapabilities' => '# Capabilities restricted for this user',
        ];
        $blockTexts = [];
        foreach ($headingsByBlock as $blockName => $blockHeading) {
            $blockBefore = $productBlocksBefore[$blockName] ?? null;
            $blockAfter = $productBlocksAfter[$blockName] ?? null;
            if ($blockBefore === $blockAfter) {
                continue;
            }
            $blockTexts[] = $blockHeading . "\n\n" . (trim((string)$blockAfter) !== '' ? trim((string)$blockAfter) : '(none any more)');
        }
        return self::PLAN_OF_THE_PRODUCT_LEAD . "\n\n" . implode("\n\n", $blockTexts) . "\n\n" . self::BACKGROUND_SUFFIX;
    }

    /** The situation note: today's provenance note, or that every value is confirmed now. */
    public static function buildSituationText(?string $situationNoteAfter): string
    {
        return $situationNoteAfter !== null && $situationNoteAfter !== '' ? $situationNoteAfter : self::SITUATION_ALL_CONFIRMED;
    }
}
