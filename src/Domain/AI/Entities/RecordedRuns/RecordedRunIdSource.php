<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

/**
 * The pinned id source of a recorded or replayed run: the tokens
 * and UUIDs a switched site mints are derived from a seed and a running counter instead of the random generator, so the
 * n-th id of a recording and of its replay are the same bytes. Pure — a hash chain, no randomness, no clock.
 *
 * @phpstan-consistent-constructor
 */
class RecordedRunIdSource
{
    /** The seed both runs share (the recording stores it). */
    public string $seed = '';

    /** How many ids this source minted. */
    public int $mintedIdCount = 0;

    public static function seeded(string $seed): static
    {
        $recordedRunIdSource = new static();
        $recordedRunIdSource->seed = $seed;
        return $recordedRunIdSource;
    }

    /** The next token as lowercase hex of $byteCount bytes — the deterministic twin of `bin2hex(random_bytes($byteCount))`. */
    public function mintHexToken(int $byteCount): string
    {
        return bin2hex($this->mintBytes($byteCount));
    }

    /** The next id as a version-4-shaped UUID (`xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`). */
    public function mintUuid(): string
    {
        $uuidBytes = $this->mintBytes(16);
        $uuidBytes[6] = chr((ord($uuidBytes[6]) & 0x0f) | 0x40);
        $uuidBytes[8] = chr((ord($uuidBytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuidBytes), 4));
    }

    /** The next $byteCount bytes of the hash chain over seed and counter. */
    protected function mintBytes(int $byteCount): string
    {
        $this->mintedIdCount++;
        $mintedBytes = '';
        for ($blockIndex = 0; strlen($mintedBytes) < max(1, $byteCount); $blockIndex++) {
            $mintedBytes .= hash('sha256', $this->seed . ':' . $this->mintedIdCount . ':' . $blockIndex, true);
        }
        return substr($mintedBytes, 0, max(1, $byteCount));
    }
}
