<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Domain\AI\Entities\Connections\AgentConnectionStepArguments;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEdit;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEdits;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveChanges;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionRelease;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Libs\Connections\Documents\GoogleDocumentEditResolver;
use DDD\Domain\AI\Libs\Connections\Documents\GoogleDocumentStructuredReader;
use DDD\Domain\AI\Libs\Connections\Google\GoogleDriveWorkspaceClient;
use DDD\Domain\AI\Services\Connections\Adapters\Fake\FakeAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccess;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveScopeService;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveStepsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Services\DDDService;

/**
 * Drive and native documents: the structured reader (anchors, revision), the edit resolver (descending indexes, unknown
 * anchors, Slides limits), the fake adapter's stale-revision refusal, the roots walk (fail closed, ancestry, search
 * and change feed narrowed to the roots), and the steps' sheet fingerprint and revision checks.
 */
class DriveDocumentsTest extends UnitTestCase
{
    protected const array DOCUMENT_JSON = [
        'documentId' => 'doc-1', 'title' => 'Plan', 'revisionId' => '7',
        'body' => ['content' => [
            ['startIndex' => 1, 'endIndex' => 8, 'paragraph' => ['paragraphStyle' => ['namedStyleType' => 'HEADING_1'], 'elements' => [['textRun' => ['content' => "Intro\n"]]]]],
            ['startIndex' => 8, 'endIndex' => 20, 'paragraph' => ['elements' => [['textRun' => ['content' => "Hello world\n"]]]]],
            ['startIndex' => 20, 'endIndex' => 40, 'table' => ['tableRows' => [
                ['tableCells' => [
                    ['startIndex' => 21, 'endIndex' => 25, 'content' => [['paragraph' => ['elements' => [['textRun' => ['content' => "A\n"]]]]]]],
                    ['startIndex' => 25, 'endIndex' => 29, 'content' => [['paragraph' => ['elements' => [['textRun' => ['content' => "B\n"]]]]]]],
                ]],
            ]]],
        ]],
        'namedRanges' => ['Budget' => ['namedRanges' => [['ranges' => [['startIndex' => 8, 'endIndex' => 13]]]]]],
    ];

    protected function setUp(): void
    {
        FakeAgentConnectionAdapter::reset();
        FakeAgentConnectionAdapter::seed(['drive' => [
            'items' => [
                ['id' => 'root-a', 'name' => 'Released', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['my-drive'], 'version' => '1'],
                ['id' => 'sub-a', 'name' => 'Sub', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['root-a'], 'version' => '1'],
                ['id' => 'doc-1', 'name' => 'Plan', 'mimeType' => 'application/vnd.google-apps.document', 'parents' => ['sub-a'], 'headRevisionId' => '7'],
                ['id' => 'sheet-1', 'name' => 'Numbers', 'mimeType' => 'application/vnd.google-apps.spreadsheet', 'parents' => ['root-a'], 'version' => '3'],
                ['id' => 'secret-1', 'name' => 'Plan secret', 'mimeType' => 'text/plain', 'parents' => ['other'], 'version' => '1', 'content' => 'x'],
                ['id' => 'other', 'name' => 'Other', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['my-drive'], 'version' => '1'],
            ],
            'documents' => ['doc-1' => self::DOCUMENT_JSON],
            'sheets' => ['sheet-1' => ['ranges' => ['Sheet1!A1:B2' => [['1', '=A1*2'], ['x', '']]]]],
        ]]);
    }

    public function testTheReaderAnchorsParagraphsTablesAndNamedRanges(): void
    {
        $documentRead = GoogleDocumentStructuredReader::readDocument(self::DOCUMENT_JSON, 10000);
        self::assertSame('7', $documentRead->revisionId);
        self::assertStringContainsString('[p:1] # Intro', $documentRead->markdown);
        self::assertStringContainsString('[p:2] Hello world', $documentRead->markdown);
        self::assertStringContainsString('| A | B |', $documentRead->markdown);
        self::assertSame(21, $documentRead->anchors->findByAnchor('tbl:1 r1c1')?->startIndex);
        self::assertSame(8, $documentRead->anchors->findByAnchor('nr:Budget')?->startIndex);
        self::assertFalse($documentRead->isTruncated);
        self::assertTrue(GoogleDocumentStructuredReader::readDocument(self::DOCUMENT_JSON, 10)->isTruncated);
    }

    public function testTheResolverOrdersByDescendingIndexAndCarriesTheRevision(): void
    {
        $documentRead = GoogleDocumentStructuredReader::readDocument(self::DOCUMENT_JSON, 10000);
        $documentEdits = AgentConnectionDocumentEdits::newInstance();
        $documentEdit = $this->buildEdit(AgentConnectionDocumentEdit::ACTION_REPLACE, 'p:1', 'Overview');
        $documentEdits->add($documentEdit);
        $documentEdit = $this->buildEdit(AgentConnectionDocumentEdit::ACTION_REPLACE, 'p:2', 'Hi');
        $documentEdits->add($documentEdit);
        $documentEdit = $this->buildEdit(AgentConnectionDocumentEdit::ACTION_REPLACE_ALL, '', 'Euro', 'EUR');
        $documentEdits->add($documentEdit);
        $documentEditRequests = GoogleDocumentEditResolver::resolve($documentRead, $documentEdits);
        self::assertSame('doc-1', $documentEditRequests->documentId);
        self::assertSame('7', $documentEditRequests->requiredRevisionId);
        $providerRequests = json_decode($documentEditRequests->providerRequestsJson, true);
        self::assertArrayHasKey('replaceAllText', $providerRequests[0]);
        $startIndexes = [];
        foreach ($providerRequests as $request) {
            if (isset($request['deleteContentRange'])) {
                $startIndexes[] = $request['deleteContentRange']['range']['startIndex'];
            }
        }
        self::assertSame([8, 1], $startIndexes, 'the later position is changed first, so earlier indexes stay valid');
    }

    public function testTheResolverRefusesAnUnknownAnchor(): void
    {
        $documentEdits = AgentConnectionDocumentEdits::newInstance();
        $documentEdit = $this->buildEdit(AgentConnectionDocumentEdit::ACTION_DELETE, 'p:99', '');
        $documentEdits->add($documentEdit);
        $this->expectException(AgentConnectionRefusedException::class);
        GoogleDocumentEditResolver::resolve(GoogleDocumentStructuredReader::readDocument(self::DOCUMENT_JSON, 10000), $documentEdits);
    }

    public function testTheResolverRefusesInsertAfterOnSlides(): void
    {
        $presentationRead = GoogleDocumentStructuredReader::readPresentation(['presentationId' => 'pres-1', 'revisionId' => '2', 'slides' => [
            ['pageElements' => [['objectId' => 'shape-1', 'shape' => ['text' => ['textElements' => [['textRun' => ['content' => 'Title']]]]]]]],
        ]], 10000);
        self::assertNotNull($presentationRead->anchors->findByAnchor('s:1 obj:shape-1'));
        $documentEdits = AgentConnectionDocumentEdits::newInstance();
        $documentEdit = $this->buildEdit(AgentConnectionDocumentEdit::ACTION_INSERT_AFTER, 's:1 obj:shape-1', 'More');
        $documentEdits->add($documentEdit);
        $this->expectException(AgentConnectionRefusedException::class);
        GoogleDocumentEditResolver::resolve($presentationRead, $documentEdits);
    }

    public function testTheFakeRefusesAnEditOnAStaleRevision(): void
    {
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $documentEdits = AgentConnectionDocumentEdits::newInstance();
        $documentEdit = $this->buildEdit(AgentConnectionDocumentEdit::ACTION_REPLACE, 'p:2', 'Hi');
        $documentEdits->add($documentEdit);
        $documentEditRequests = GoogleDocumentEditResolver::resolve($fakeAdapter->readGoogleDoc('doc-1', 10000), $documentEdits);
        $fakeAdapter->editGoogleDoc($documentEditRequests);
        self::assertSame('8', $fakeAdapter->getDriveItem('doc-1')->getRevisionKey(), 'a write bumps the revision');
        $this->expectException(AgentConnectionRefusedException::class);
        $fakeAdapter->editGoogleDoc($documentEditRequests);
    }

    public function testNoRootsMeansNothingIsVisible(): void
    {
        $scopeService = $this->getDriveScopeService();
        $agentConnection = $this->buildConnection(null);
        self::assertFalse($scopeService->isInsideRoots(new FakeAgentConnectionAdapter(), $agentConnection, AgentConnectionAccess::asOwner(), (new FakeAgentConnectionAdapter())->getDriveItem('doc-1')));
        $this->expectException(AgentConnectionRefusedException::class);
        $scopeService->requireItemInsideRoots(new FakeAgentConnectionAdapter(), $agentConnection, AgentConnectionAccess::asOwner(), 'doc-1');
    }

    public function testTheAncestryWalkFindsDescendantsAndRefusesTheRest(): void
    {
        $scopeService = $this->getDriveScopeService();
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $agentConnection = $this->buildConnection('FOLDER:root-a');
        self::assertSame('doc-1', $scopeService->requireItemInsideRoots($fakeAdapter, $agentConnection, AgentConnectionAccess::asOwner(), 'doc-1')->itemId);
        self::assertTrue($scopeService->isInsideRoots($fakeAdapter, $agentConnection, AgentConnectionAccess::asOwner(), $fakeAdapter->getDriveItem('root-a')));
        $searchHits = $scopeService->searchInsideRoots($fakeAdapter, $agentConnection, AgentConnectionAccess::asOwner(), 'plan', 10);
        self::assertSame(['doc-1'], array_map([self::class, 'itemIdOf'], $searchHits->getElements()), 'a hit outside the roots is dropped');
        $this->expectException(AgentConnectionRefusedException::class);
        $scopeService->requireItemInsideRoots($fakeAdapter, $agentConnection, AgentConnectionAccess::asOwner(), 'secret-1');
    }

    public function testAReleaseNarrowsTheRootsAndDisjointRootsAllowNothing(): void
    {
        $scopeService = $this->getDriveScopeService();
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $agentConnection = $this->buildConnection("FOLDER:root-a\nFOLDER:other");
        $agentConnectionRelease = AgentConnectionRelease::newInstance();
        $agentConnectionRelease->scopeBoundaries = AgentConnectionScopeBoundaries::ofBoundaries(AgentConnectionScopeBoundary::of('FOLDER', 'other'));
        $releaseAccess = AgentConnectionAccess::throughRelease($agentConnectionRelease);
        self::assertTrue($scopeService->isInsideRoots($fakeAdapter, $agentConnection, $releaseAccess, $fakeAdapter->getDriveItem('secret-1')));
        self::assertFalse($scopeService->isInsideRoots($fakeAdapter, $agentConnection, $releaseAccess, $fakeAdapter->getDriveItem('doc-1')));
        $agentConnectionRelease->scopeBoundaries = AgentConnectionScopeBoundaries::ofBoundaries(AgentConnectionScopeBoundary::of('FOLDER', 'elsewhere'));
        self::assertSame([], $scopeService->findRootIds($agentConnection, AgentConnectionAccess::throughRelease($agentConnectionRelease)));
    }

    public function testTheChangeFeedTreatsAnItemOutsideTheRootsAsRemoved(): void
    {
        $scopeService = $this->getDriveScopeService();
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $driveChanges = AgentConnectionDriveChanges::newInstance();
        $insideItem = $fakeAdapter->getDriveItem('doc-1');
        $outsideItem = $fakeAdapter->getDriveItem('secret-1');
        $driveChanges->changedItems->add($insideItem);
        $driveChanges->changedItems->add($outsideItem);
        $filteredChanges = $scopeService->filterChangesToRoots($fakeAdapter, $this->buildConnection('FOLDER:root-a'), AgentConnectionAccess::asOwner(), $driveChanges);
        self::assertSame(['doc-1'], array_map([self::class, 'itemIdOf'], $filteredChanges->changedItems->getElements()));
        self::assertSame(['secret-1'], $filteredChanges->removedItemIds);
    }

    public function testAFolderItemKnowsItsKind(): void
    {
        $driveItem = GoogleDriveWorkspaceClient::toDriveItem(['id' => 'f', 'name' => 'F', 'mimeType' => 'application/vnd.google-apps.folder', 'version' => '4']);
        self::assertTrue($driveItem->isFolder());
        self::assertSame(AgentConnectionDriveItem::KIND_FOLDER, $driveItem->kind);
    }

    public function testDriveStepsAreRecognisedByCapabilityAndKind(): void
    {
        $agentConnectionOperation = AgentConnectionOperation::create($this->buildConnection('FOLDER:root-a'), AgentConnectionPermission::CAPABILITY_FILES, AgentConnectionAction::OPERATION_SETTINGS, 'trashDriveItem', 1, null);
        $agentConnectionOperation->stepArguments = AgentConnectionStepArguments::forDriveStep(AgentConnectionDriveStepsService::STEP_TRASH);
        $agentConnectionOperation->stepArguments->itemId = 'doc-1';
        self::assertTrue(AgentConnectionDriveStepsService::isDriveStep($agentConnectionOperation));
        $agentConnectionOperation->stepArguments = AgentConnectionStepArguments::forDriveStep('SEND');
        self::assertFalse(AgentConnectionDriveStepsService::isDriveStep($agentConnectionOperation));
    }

    public function testASheetWriteNeedsTheFingerprintOfAnUnchangedRead(): void
    {
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $stepsService = new DriveTestStepsService();
        $readFingerprint = $fakeAdapter->readSheetRange('sheet-1', 'Sheet1!A1:B2')->getFingerprint();
        $stepArguments = AgentConnectionStepArguments::forDriveStep(AgentConnectionDriveStepsService::STEP_UPDATE_SHEET, range: 'Sheet1!A1:B2', rowsJson: (string)json_encode([['2', '=A1*2'], ['y', '']]), readFingerprint: $readFingerprint);
        $stepsService->updateSheetForTest($fakeAdapter, 'sheet-1', $stepArguments);
        self::assertSame([['2', '=A1*2'], ['y', '']], $fakeAdapter->readSheetRange('sheet-1', 'Sheet1!A1:B2')->rows);
        $this->expectException(AgentConnectionRefusedException::class);
        $stepsService->updateSheetForTest($fakeAdapter, 'sheet-1', $stepArguments);
    }

    public function testAnEditStepWithoutItsRevisionIsRefused(): void
    {
        $this->expectException(AgentConnectionRefusedException::class);
        $stepArguments = AgentConnectionStepArguments::forDriveStep(AgentConnectionDriveStepsService::STEP_EDIT_DOC, providerRequestsJson: '[{"deleteContentRange":{}}]');
        $stepArguments->itemId = 'doc-1';
        (new DriveTestStepsService())->buildEditRequestsForTest($stepArguments);
    }

    public static function itemIdOf(AgentConnectionDriveItem $driveItem): string
    {
        return $driveItem->itemId;
    }

    /** @param string|null $folderLines the boundaries as KIND:value lines (test shorthand) */
    protected function buildConnection(?string $folderLines): AgentConnection
    {
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->id = 41;
        $agentConnection->displayName = 'Team drive';
        if ($folderLines !== null) {
            $scopeBoundaries = new AgentConnectionScopeBoundaries();
            foreach (explode("\n", $folderLines) as $folderLine) {
                [$kind, $value] = explode(':', $folderLine, 2);
                $scopeBoundaries = $scopeBoundaries->withBoundary($kind, $value, true);
            }
            $agentConnection->scopeBoundaries = $scopeBoundaries;
        }
        return $agentConnection;
    }

    protected function buildEdit(string $action, string $anchor, string $text, ?string $findText = null): AgentConnectionDocumentEdit
    {
        $documentEdit = AgentConnectionDocumentEdit::newInstance();
        $documentEdit->action = $action;
        $documentEdit->anchor = $anchor;
        $documentEdit->text = $text;
        $documentEdit->findText = $findText;
        return $documentEdit;
    }

    protected function getDriveScopeService(): AgentConnectionDriveScopeService
    {
        /** @var AgentConnectionDriveScopeService $agentConnectionDriveScopeService */
        $agentConnectionDriveScopeService = DDDService::instance()->getService(AgentConnectionDriveScopeService::class);
        return $agentConnectionDriveScopeService;
    }
}
