<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContactsScope;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\Fake\FakeAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionContactMergesService;
use DDD\Tests\Agents\Support\UnitTestCase;

/** Contacts: the scope (sources, label allowlist, fail closed), the merge plan, the fake adapter's etag and label rules. */
class ContactsTest extends UnitTestCase
{
    protected function setUp(): void
    {
        FakeAgentConnectionAdapter::reset();
        FakeAgentConnectionAdapter::seed($this->buildWorld('e1', 'e2'));
    }

    /** @return array<string, mixed> */
    protected function buildWorld(string $survivorEtag, string $donorEtag): array
    {
        return [
            'contactGroups' => [['groupId' => 'contactGroups/partners', 'name' => 'Partners'], ['groupId' => 'contactGroups/private', 'name' => 'Private']],
            'contacts' => [
                ['contactId' => 'people/c1', 'name' => 'Anna Kaltofen', 'emailAddresses' => ['anna@example.org'], 'organization' => 'Example GmbH', 'labels' => ['contactGroups/partners'], 'etag' => $survivorEtag, 'source' => 'CONTACTS'],
                ['contactId' => 'people/c2', 'name' => 'Anna K.', 'emailAddresses' => ['anna@example.org'], 'phoneNumbers' => ['+49 30 1234'], 'labels' => ['contactGroups/private'], 'etag' => $donorEtag, 'source' => 'CONTACTS', 'hasPhoto' => true],
                ['contactId' => 'otherContacts/o1', 'name' => 'Otto Other', 'emailAddresses' => ['otto@example.net'], 'source' => 'OTHER_CONTACTS'],
                ['contactId' => 'people/d1', 'name' => 'Dora Directory', 'emailAddresses' => ['dora@example.org'], 'source' => 'DIRECTORY'],
            ],
        ];
    }

    public function testDefaultSourcesLeaveOtherContactsOut(): void
    {
        $contacts = (new FakeAgentConnectionAdapter())->searchContacts('', 10, AgentConnectionContactsScope::newInstance());
        $contactIds = [];
        foreach ($contacts->getElements() as $contact) {
            $contactIds[] = $contact->contactId;
        }
        self::assertSame(['people/c1', 'people/c2', 'people/d1'], $contactIds);
    }

    public function testALabelAllowlistHidesPeopleOutsideIt(): void
    {
        $contactsScope = AgentConnectionContactsScope::fromBoundaryValues(null, ['contactGroups/partners']);
        $contacts = (new FakeAgentConnectionAdapter())->searchContacts('anna', 10, $contactsScope);
        self::assertCount(1, $contacts->getElements());
        self::assertSame('people/c1', $contacts->first()?->contactId);
        self::assertNull((new FakeAgentConnectionAdapter())->getContact('people/c2', $contactsScope));
        self::assertCount(1, (new FakeAgentConnectionAdapter())->listContactGroups($contactsScope)->getElements());
    }

    public function testAnEmptyIntersectionAllowsNothing(): void
    {
        $contactsScope = AgentConnectionContactsScope::fromBoundaryValues([], []);
        self::assertCount(0, (new FakeAgentConnectionAdapter())->searchContacts('', 10, $contactsScope)->getElements());
    }

    public function testTheDomainPathFiltersByEmailDomain(): void
    {
        $contacts = (new FakeAgentConnectionAdapter())->findContactsByDomainOrOrganization('@example.org', 10, AgentConnectionContactsScope::newInstance());
        self::assertCount(3, $contacts->getElements());
    }

    public function testAStaleEtagIsRefused(): void
    {
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $contact = $fakeAdapter->getContact('people/c1', AgentConnectionContactsScope::newInstance());
        self::assertNotNull($contact);
        $contact->etag = 'old';
        $this->expectException(AgentConnectionRefusedException::class);
        $fakeAdapter->updateContact($contact, ['phoneNumbers']);
    }

    public function testTheMergePlanKeepsBothAddressesAndNamesTheLoss(): void
    {
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $survivor = $fakeAdapter->getContact('people/c1', AgentConnectionContactsScope::newInstance());
        $donor = $fakeAdapter->getContact('people/c2', AgentConnectionContactsScope::newInstance());
        $mergePlan = $this->getContactMergesService()->buildMergePlan($survivor, $donor);
        self::assertSame(['contactGroups/private'], $mergePlan->labelsToAdd);
        self::assertStringContainsString('Anna K.', implode(' ', $mergePlan->lostLines));
        self::assertStringContainsString('donor is deleted', $mergePlan->describeForApproval());
    }

    public function testAMergeWithAnOtherContactIsRefused(): void
    {
        $other = AgentConnectionContact::newInstance();
        $other->contactId = 'otherContacts/o1';
        $other->source = AgentConnectionContact::SOURCE_OTHER_CONTACTS;
        $survivor = AgentConnectionContact::newInstance();
        $survivor->contactId = 'people/c1';
        $this->expectException(AgentConnectionRefusedException::class);
        $this->getContactMergesService()->buildMergePlan($survivor, $other);
    }

    public function testAMergeRefusesADonorChangedSinceThePlan(): void
    {
        $agentConnectionOperation = $this->buildPlannedMerge();
        FakeAgentConnectionAdapter::seed($this->buildWorld('e1', 'e2-changed'));

        $this->expectException(AgentConnectionRefusedException::class);
        $this->getContactMergesService()->executeMerge($agentConnectionOperation, new FakeAgentConnectionAdapter());
    }

    public function testAMergeRefusesASurvivorChangedSinceThePlanEvenWithoutFieldChanges(): void
    {
        $agentConnectionOperation = $this->buildPlannedMerge();
        $agentConnectionOperation->stepArguments->fieldNames = [];
        FakeAgentConnectionAdapter::seed($this->buildWorld('e1-changed', 'e2'));

        $this->expectException(AgentConnectionRefusedException::class);
        $this->getContactMergesService()->executeMerge($agentConnectionOperation, new FakeAgentConnectionAdapter());
    }

    public function testAHalfDoneMergeRunsAgainToItsEnd(): void
    {
        $agentConnectionOperation = $this->buildPlannedMerge();
        $fakeAdapter = new FakeAgentConnectionAdapter();
        // a first run that updated the survivor and stopped before the donor's delete
        $fakeAdapter->updateContact($agentConnectionOperation->stepArguments->contact, ['phoneNumbers']);

        $sendResult = $this->getContactMergesService()->executeMerge($agentConnectionOperation, $fakeAdapter);
        self::assertSame('people/c1', $sendResult->externalId);
        self::assertNull($fakeAdapter->getContact('people/c2', AgentConnectionContactsScope::newInstance()), 'the donor is deleted on the second run');
        self::assertSame('people/c1', $this->getContactMergesService()->executeMerge($agentConnectionOperation, $fakeAdapter)->externalId, 'a donor already gone is a finished merge');
    }

    protected function buildPlannedMerge(): AgentConnectionOperation
    {
        $fakeAdapter = new FakeAgentConnectionAdapter();
        $survivor = $fakeAdapter->getContact('people/c1', AgentConnectionContactsScope::newInstance());
        $donor = $fakeAdapter->getContact('people/c2', AgentConnectionContactsScope::newInstance());
        $mergePlan = $this->getContactMergesService()->buildMergePlan($survivor, $donor);
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->id = 9;
        $agentConnectionOperation = AgentConnectionOperation::create($agentConnection, AgentConnectionPermission::CAPABILITY_CONTACTS, AgentConnectionAction::OPERATION_MERGE, 'mergeContacts', 1, null);
        $agentConnectionOperation->stepArguments->contact = $mergePlan->survivor;
        $agentConnectionOperation->stepArguments->contactId = $mergePlan->donorContactId;
        $agentConnectionOperation->stepArguments->donorEtag = $mergePlan->donorEtag;
        $agentConnectionOperation->stepArguments->fieldNames = $mergePlan->fieldNames;
        $agentConnectionOperation->stepArguments->labelsToAdd = $mergePlan->labelsToAdd;
        return $agentConnectionOperation;
    }

    protected function getContactMergesService(): AgentConnectionContactMergesService
    {
        /** @var AgentConnectionContactMergesService $agentConnectionContactMergesService */
        $agentConnectionContactMergesService = DDDService::instance()->getService(AgentConnectionContactMergesService::class);
        return $agentConnectionContactMergesService;
    }
}
