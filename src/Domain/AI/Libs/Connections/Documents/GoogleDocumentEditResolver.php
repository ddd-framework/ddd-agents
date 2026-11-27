<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\Connections\Documents;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEdit;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEditRequests;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEdits;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentRead;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;

/**
 * Resolves the agent's anchor edits against the read revision into batchUpdate requests (Docs or Slides), locked on
 * that revision. Docs edits run from the highest index down, so an edit never moves the index of a later one. Pure,
 * unit tested.
 */
class GoogleDocumentEditResolver
{
    /** @throws AgentConnectionRefusedException an unknown anchor or an action the document kind cannot take */
    public static function resolve(AgentConnectionDocumentRead $documentRead, AgentConnectionDocumentEdits $documentEdits): AgentConnectionDocumentEditRequests
    {
        $documentEditRequests = AgentConnectionDocumentEditRequests::newInstance();
        $documentEditRequests->documentId = $documentRead->itemId;
        $documentEditRequests->requiredRevisionId = $documentRead->revisionId;
        if ($documentRead->revisionId === '') {
            throw new AgentConnectionRefusedException('The read carries no revision; read the document again before editing.');
        }
        $positionedRequests = [];
        $replaceAllRequests = [];
        foreach ($documentEdits->getElements() as $documentEdit) {
            if ($documentEdit->action === AgentConnectionDocumentEdit::ACTION_REPLACE_ALL) {
                if ((string)$documentEdit->findText === '') {
                    throw new AgentConnectionRefusedException('REPLACE_ALL needs findText.');
                }
                $replaceAllRequests[] = ['replaceAllText' => [
                    'containsText' => ['text' => (string)$documentEdit->findText, 'matchCase' => true],
                    'replaceText' => $documentEdit->text,
                ]];
                continue;
            }
            $documentAnchor = $documentRead->anchors->findByAnchor($documentEdit->anchor);
            if ($documentAnchor === null) {
                throw new AgentConnectionRefusedException(sprintf('The anchor "%s" is not in the read of revision %s; read the document again.', $documentEdit->anchor, $documentRead->revisionId));
            }
            if ($documentRead->kind === AgentConnectionDriveItem::KIND_GOOGLE_SLIDES) {
                $positionedRequests[] = [0, static::slidesRequests($documentAnchor->objectId ?? '', $documentEdit)];
                continue;
            }
            $positionedRequests[] = [$documentAnchor->startIndex, static::docsRequests($documentAnchor->startIndex, $documentAnchor->endIndex, $documentEdit)];
        }
        usort($positionedRequests, [static::class, 'compareByIndexDescending']);
        $requests = $replaceAllRequests;
        foreach ($positionedRequests as $positionedRequest) {
            foreach ($positionedRequest[1] as $request) {
                $requests[] = $request;
            }
        }
        $documentEditRequests->providerRequestsJson = (string)json_encode($requests);
        return $documentEditRequests;
    }

    /**
     * @param array{0:int, 1:array<int, array<string, mixed>>} $first
     * @param array{0:int, 1:array<int, array<string, mixed>>} $second
     */
    public static function compareByIndexDescending(array $first, array $second): int
    {
        return $second[0] <=> $first[0];
    }

    /** @return array<int, array<string, mixed>> */
    protected static function docsRequests(int $startIndex, int $endIndex, AgentConnectionDocumentEdit $documentEdit): array
    {
        // the element's last character is its paragraph mark; it stays
        $textEndIndex = max($startIndex, $endIndex - 1);
        return match ($documentEdit->action) {
            AgentConnectionDocumentEdit::ACTION_REPLACE => array_values(array_filter([
                $textEndIndex > $startIndex ? ['deleteContentRange' => ['range' => ['startIndex' => $startIndex, 'endIndex' => $textEndIndex]]] : null,
                $documentEdit->text !== '' ? ['insertText' => ['location' => ['index' => $startIndex], 'text' => $documentEdit->text]] : null,
            ])),
            AgentConnectionDocumentEdit::ACTION_INSERT_AFTER => [
                ['insertText' => ['location' => ['index' => $textEndIndex], 'text' => "\n" . $documentEdit->text]],
            ],
            AgentConnectionDocumentEdit::ACTION_DELETE => [
                ['deleteContentRange' => ['range' => ['startIndex' => $startIndex, 'endIndex' => max($startIndex + 1, $endIndex)]]],
            ],
            default => throw new AgentConnectionRefusedException('Unknown edit action ' . $documentEdit->action . '.'),
        };
    }

    /** @return array<int, array<string, mixed>> */
    protected static function slidesRequests(string $objectId, AgentConnectionDocumentEdit $documentEdit): array
    {
        if ($objectId === '') {
            throw new AgentConnectionRefusedException('This anchor names no slide object.');
        }
        return match ($documentEdit->action) {
            AgentConnectionDocumentEdit::ACTION_REPLACE => [
                ['deleteText' => ['objectId' => $objectId, 'textRange' => ['type' => 'ALL']]],
                ['insertText' => ['objectId' => $objectId, 'insertionIndex' => 0, 'text' => $documentEdit->text]],
            ],
            AgentConnectionDocumentEdit::ACTION_INSERT_AFTER => throw new AgentConnectionRefusedException('A slide object takes REPLACE or DELETE; to add text, REPLACE it with the old text plus the new.'),
            AgentConnectionDocumentEdit::ACTION_DELETE => [
                ['deleteText' => ['objectId' => $objectId, 'textRange' => ['type' => 'ALL']]],
            ],
            default => throw new AgentConnectionRefusedException('Unknown edit action ' . $documentEdit->action . '.'),
        };
    }
}
