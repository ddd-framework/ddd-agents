<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A STORED v1 supervisor verdict: the JSON object an ASSISTANT text row of a tandem carried before the supervisor decided
 * with terminal tool calls. Read only by the admin inspector's supervisor rail, so old tandems show
 * as before; the live checkpoint never parses a model's prose — a
 * tool-less answer is no decision there.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorLegacyVerdict extends ValueObject
{
    /** Whether the verdict judged the trajectory healthy (null = the key is missing). */
    public ?bool $trajectoryHealthy = null;

    /** The verdict's diagnosis (null = the key is missing or null). */
    public ?string $diagnosis = null;

    /** The move the verdict asked for (null = the key is missing or null). */
    public ?string $signal = null;

    /** The tool the verdict expected next, trimmed ('' = none). */
    public string $expectedToolName = '';

    /** @var array<int|string, mixed> The plan operations the verdict suggested ([] = none, or not a list). */
    public array $planOps = [];

    /**
     * The verdict in a stored text: the FIRST complete, decodable JSON object in it (robust to a ```json fence, a prose
     * preamble, trailing prose or a second object); null when the text holds none, or only an empty one.
     */
    public static function parseFromText(string $verdictText): ?static
    {
        $verdictFields = self::extractFirstJsonObject($verdictText);
        if ($verdictFields === []) {
            return null;
        }
        $supervisorLegacyVerdict = new static();
        $supervisorLegacyVerdict->trajectoryHealthy = array_key_exists('trajectoryHealthy', $verdictFields) ? (bool)$verdictFields['trajectoryHealthy'] : null;
        $supervisorLegacyVerdict->diagnosis = isset($verdictFields['diagnosis']) ? (string)$verdictFields['diagnosis'] : null;
        $supervisorLegacyVerdict->signal = isset($verdictFields['signal']) ? (string)$verdictFields['signal'] : null;
        $supervisorLegacyVerdict->expectedToolName = trim((string)($verdictFields['expectedToolName'] ?? ''));
        $planOps = array_key_exists('planOps', $verdictFields) ? $verdictFields['planOps'] : null;
        $supervisorLegacyVerdict->planOps = is_array($planOps) ? $planOps : [];
        return $supervisorLegacyVerdict;
    }

    /**
     * Whether the verdict steered: the steering text the v1 supervised agent read — the imperative first, the diagnosis
     * as a short "why", the `Expected tool:` line, the plan operations as advisory prose; null for a healthy verdict or
     * one without a move.
     */
    public function buildSteeringText(): ?string
    {
        $signalText = trim((string)($this->signal ?? ''));
        if ($signalText === '' || (bool)($this->trajectoryHealthy ?? false)) {
            return null;
        }
        $diagnosis = trim((string)($this->diagnosis ?? ''));
        $steeringLines = ['[SUPERVISOR] Do this NOW: ' . $signalText];
        if ($diagnosis !== '') {
            $steeringLines[] = '(Why: ' . mb_substr($diagnosis, 0, 220) . ')';
        }
        if ($this->expectedToolName !== '' && preg_match('/^[A-Za-z0-9_]+$/', $this->expectedToolName) === 1) {
            $steeringLines[] = 'Expected tool: ' . $this->expectedToolName;
        }
        if ($this->planOps !== []) {
            $steeringLines[] = 'Suggested plan adjustments (advisory): '
                . json_encode($this->planOps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $steeringLines);
    }

    /**
     * The FIRST complete, decodable JSON object in a model's raw text: each `{` is a candidate start, the brace depth is
     * tracked outside string literals and escapes, and the first balanced object that `json_decode`s to an array wins.
     * A greedy `/\{.*\}/s` overshoots into trailing prose. Genuine truncation (no closing brace) → `[]`. Byte-scanning
     * is UTF-8-safe: the structural characters are ASCII, a multibyte continuation byte is always ≥ 0x80.
     *
     * @return array<string, mixed>
     */
    public static function extractFirstJsonObject(string $rawText): array
    {
        $length = strlen($rawText);
        $offset = 0;
        while (($start = strpos($rawText, '{', $offset)) !== false) {
            $depth = 0;
            $inString = false;
            $escaped = false;
            for ($position = $start; $position < $length; $position++) {
                $character = $rawText[$position];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $inString = false;
                    }
                    continue;
                }
                if ($character === '"') {
                    $inString = true;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $decoded = json_decode(substr($rawText, $start, $position - $start + 1), true);
                        if (is_array($decoded)) {
                            return $decoded;
                        }
                        break; // this candidate did not decode → advance to the next '{'
                    }
                }
            }
            $offset = $start + 1;
        }
        return [];
    }
}
