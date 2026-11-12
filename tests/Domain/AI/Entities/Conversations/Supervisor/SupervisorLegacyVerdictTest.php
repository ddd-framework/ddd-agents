<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorLegacyVerdict;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * {@see SupervisorLegacyVerdict} reads a stored v1 verdict text
 * exactly as `SupervisorCheckpointService::parseVerdict()` + `buildSteeringText()` did before they were deleted
 * (frozen below) and as the admin rail projected the parsed fields — so the admin view of old tandems is unchanged.
 */
class SupervisorLegacyVerdictTest extends UnitTestCase
{
    /** @return string[] Stored verdict shapes: fenced, prose around, healthy, empty fields, odd types, garbage. */
    public static function verdictTexts(): array
    {
        return [
            "```json\n{\"diagnosis\": \"The hours were never asked.\", \"trajectoryHealthy\": false, \"signal\": \"Ask for the opening hours.\", \"expectedToolName\": \"askOwner\", \"planOps\": [{\"op\": \"reopen\", \"task\": \"3.4\"}]}\n```",
            '{"diagnosis": "fine", "trajectoryHealthy": true, "signal": "", "expectedToolName": null}',
            "Here is my verdict.\n{\"diagnosis\": \"" . str_repeat('lang ', 80) . "\", \"trajectoryHealthy\": false, \"signal\": \"  Fix it.  \", \"expectedToolName\": \" update-Profile \", \"planOps\": []}\nDone. {\"second\": 1}",
            '{"trajectoryHealthy": null, "signal": "Go on.", "expectedToolName": "updateProfile", "planOps": "not a list"}',
            '{"signal": "Do it.", "diagnosis": null, "planOps": {"op": "x"}}',
            '{"diagnosis": "only a diagnosis"}',
            '{}',
            'no json here',
            '{"diagnosis": "the agent did two things wrong: (1',
            '{"a": "{nested \\"quote\\" }", "signal": "Quoted }", "trajectoryHealthy": 0}',
        ];
    }

    public function testTheVerdictReadsAsTheDeletedServiceReadIt(): void
    {
        foreach (self::verdictTexts() as $verdictText) {
            $parsedVerdict = self::frozenParseVerdict($verdictText);
            $supervisorLegacyVerdict = SupervisorLegacyVerdict::parseFromText($verdictText);
            self::assertSame($parsedVerdict === [], $supervisorLegacyVerdict === null, $verdictText);
            if ($supervisorLegacyVerdict === null) {
                continue;
            }
            self::assertSame(self::frozenBuildSteeringText($parsedVerdict), $supervisorLegacyVerdict->buildSteeringText(), $verdictText);
            // the admin rail's projection, as it stood before the commit
            self::assertSame(array_key_exists('trajectoryHealthy', $parsedVerdict) ? (bool)$parsedVerdict['trajectoryHealthy'] : null, $supervisorLegacyVerdict->trajectoryHealthy, $verdictText);
            self::assertSame(isset($parsedVerdict['diagnosis']) ? (string)$parsedVerdict['diagnosis'] : null, $supervisorLegacyVerdict->diagnosis, $verdictText);
            self::assertSame(isset($parsedVerdict['signal']) ? (string)$parsedVerdict['signal'] : null, $supervisorLegacyVerdict->signal, $verdictText);
            $expectedToolName = trim((string)($parsedVerdict['expectedToolName'] ?? ''));
            self::assertSame($expectedToolName !== '' ? $expectedToolName : null, $supervisorLegacyVerdict->expectedToolName !== '' ? $supervisorLegacyVerdict->expectedToolName : null, $verdictText);
            $verdictPlanOps = array_key_exists('planOps', $parsedVerdict) ? $parsedVerdict['planOps'] : null;
            self::assertSame(is_array($verdictPlanOps) && $verdictPlanOps !== [] ? $verdictPlanOps : null, $supervisorLegacyVerdict->planOps !== [] ? $supervisorLegacyVerdict->planOps : null, $verdictText);
        }
    }

    /** `SupervisorCheckpointService::parseVerdict()` before its removal (its JSON extraction moved unchanged). */
    protected static function frozenParseVerdict(string $raw): array
    {
        return SupervisorLegacyVerdict::extractFirstJsonObject($raw);
    }

    /**
     * `SupervisorCheckpointService::buildSteeringText()` before its removal, verbatim.
     *
     * @param array<string, mixed> $verdict
     */
    protected static function frozenBuildSteeringText(array $verdict): ?string
    {
        $signalText = trim((string)($verdict['signal'] ?? ''));
        if ($signalText === '' || (bool)($verdict['trajectoryHealthy'] ?? false)) {
            return null;
        }
        $diagnosis = trim((string)($verdict['diagnosis'] ?? ''));
        $planOps = is_array($verdict['planOps'] ?? null) ? $verdict['planOps'] : [];
        $steeringLines = ['[SUPERVISOR] Do this NOW: ' . $signalText];
        if ($diagnosis !== '') {
            $steeringLines[] = '(Why: ' . mb_substr($diagnosis, 0, 220) . ')';
        }
        $expectedToolName = trim((string)($verdict['expectedToolName'] ?? ''));
        if ($expectedToolName !== '' && preg_match('/^[A-Za-z0-9_]+$/', $expectedToolName) === 1) {
            $steeringLines[] = 'Expected tool: ' . $expectedToolName;
        }
        if ($planOps !== []) {
            $steeringLines[] = 'Suggested plan adjustments (advisory): '
                . json_encode($planOps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $steeringLines);
    }
}
