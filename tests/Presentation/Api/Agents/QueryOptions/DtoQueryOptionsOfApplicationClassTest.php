<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\QueryOptions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessagesGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsGetRequestDto;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The request classes of the conversation reads name the module's classes in `#[DtoQueryOptions]`. An application
 * that derives one of them — to keep a column of its own next to `agentSpaceId` — gets its class as the base of the
 * request: its column is a filter the request accepts and the documentation lists. Without such a class the
 * attribute is answered as declared.
 */
class DtoQueryOptionsOfApplicationClassTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    public function testWithoutAClassOfTheApplicationTheRequestsAreBasedOnTheClassesOfTheModule(): void
    {
        self::assertSame(AIConversation::class, AIConversationGetRequestDto::getDtoQueryOptions()->baseEntity);
        self::assertSame(AIConversations::class, AIConversationsGetRequestDto::getDtoQueryOptions()->baseEntity);
        self::assertSame(AIConversationMessages::class, AIConversationMessagesGetRequestDto::getDtoQueryOptions()->baseEntity);
    }

    public function testTheRequestIsBasedOnTheClassTheApplicationUsesInThePlaceOfTheModulesClass(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = ApplicationConversationFixture::class;

        $dtoQueryOptions = AIConversationGetRequestDto::getDtoQueryOptions();

        self::assertInstanceOf(DtoQueryOptions::class, $dtoQueryOptions);
        self::assertSame(ApplicationConversationFixture::class, $dtoQueryOptions->baseEntity);
        // the other request classes keep their own base
        self::assertSame(AIConversations::class, AIConversationsGetRequestDto::getDtoQueryOptions()->baseEntity);
    }

    public function testAColumnOfTheApplicationsClassIsAFilterTheRequestAccepts(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = ApplicationConversationFixture::class;
        $requestDto = new AIConversationGetRequestDto();

        $requestDto->setPropertiesFromRequest($this->buildConversationRequestFilteredByShop());

        self::assertNotNull($requestDto->filters);
    }

    public function testWithoutTheApplicationsClassItsColumnIsRefused(): void
    {
        $requestDto = new AIConversationGetRequestDto();

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("'shopId' is not allowed");

        $requestDto->setPropertiesFromRequest($this->buildConversationRequestFilteredByShop());
    }

    protected function buildConversationRequestFilteredByShop(): Request
    {
        $request = Request::create('/conversations/5', 'GET', ['filters' => "shopId eq '7'"]);
        $request->attributes->set('aiConversationId', 5);
        return $request;
    }
}
