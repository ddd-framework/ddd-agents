<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfigModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationTaskPushNotificationConfigsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw existence probe of {@see AIConversationTaskPushNotificationConfigsService} against the database, on both
 * engines: a conversation has an enabled push config, only disabled ones, or none.
 */
class AIConversationTaskPushNotificationConfigsServiceDatabaseTest extends DatabaseTestCase
{
    protected AIConversationTaskPushNotificationConfigsService $pushNotificationConfigsService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        /** @var AIConversationTaskPushNotificationConfigsService $pushNotificationConfigsService */
        $pushNotificationConfigsService = DDDService::instance()->getService(AIConversationTaskPushNotificationConfigsService::class);
        $this->pushNotificationConfigsService = $pushNotificationConfigsService;
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheProbeAnswersWhetherAConversationHasAnEnabledConfig(): void
    {
        foreach ([100, 101, 102] as $conversationId) {
            $this->insertRow(DBAIConversationModel::getTableName(), ['id' => $conversationId, 'runState' => AIConversationRunState::IDLE]);
        }
        $this->insertConfig(1, 100, null);
        $this->insertConfig(2, 100, (new DateTime())->format(DateTime::SIMPLE));
        $this->insertConfig(3, 101, (new DateTime())->format(DateTime::SIMPLE));

        self::assertTrue($this->pushNotificationConfigsService->hasEnabledConfigsForConversation(100));
        self::assertFalse($this->pushNotificationConfigsService->hasEnabledConfigsForConversation(101), 'only disabled configs');
        self::assertFalse($this->pushNotificationConfigsService->hasEnabledConfigsForConversation(102), 'no config');
    }

    protected function insertConfig(int $configId, int $conversationId, ?string $disabledAt): void
    {
        $this->insertRow(DBAIConversationTaskPushNotificationConfigModel::getTableName(), [
            'id' => $configId,
            'aiConversationId' => $conversationId,
            'taskUserMessageId' => 1,
            'url' => 'https://example.test/push',
            'deliveryAttempts' => 0,
            'disabledAt' => $disabledAt,
        ]);
    }
}
