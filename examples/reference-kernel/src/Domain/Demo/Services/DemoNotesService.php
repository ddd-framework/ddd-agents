<?php

declare(strict_types=1);

namespace AgentsReference\Domain\Demo\Services;

use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * DEMO ONLY — the demo owner's notes, the data behind the two demo tools. Kept in one JSON file per account below the
 * host's `var/demo-notes/` (no table: the reference application keeps its own schema to the package's tables).
 */
class DemoNotesService extends Service
{
    /** @return string[] The notes of the authenticated account, oldest first */
    public function findNotesOfCurrentAccount(): array
    {
        $notesFile = $this->getNotesFileOfCurrentAccount();
        if (!is_file($notesFile)) {
            return [];
        }
        $storedNotes = json_decode((string)file_get_contents($notesFile), true);
        return is_array($storedNotes) ? array_values(array_map('strval', $storedNotes)) : [];
    }

    /** @return string[] The notes of the authenticated account after `$noteText` was appended */
    public function addNoteForCurrentAccount(string $noteText): array
    {
        $notes = $this->findNotesOfCurrentAccount();
        $notes[] = trim($noteText);
        $notesFile = $this->getNotesFileOfCurrentAccount();
        if (!is_dir(dirname($notesFile))) {
            mkdir(dirname($notesFile), 0775, true);
        }
        file_put_contents($notesFile, json_encode($notes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $notes;
    }

    protected function getNotesFileOfCurrentAccount(): string
    {
        $accountId = (int)(AuthService::instance()->getAccount()->id ?? 0);
        return DDDService::instance()->getRootDir() . '/var/demo-notes/account-' . $accountId . '.json';
    }
}
