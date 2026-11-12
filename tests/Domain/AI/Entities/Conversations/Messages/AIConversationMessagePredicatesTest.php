<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The member set of every message predicate over role (incl. an uninitialized role, as on
 * a tool-call or error row) × author (incl. a legacy null) × type: the matrix the 90 replaced inline expressions were
 * written against, so a predicate body that drifts fails here before any site does.
 */
class AIConversationMessagePredicatesTest extends UnitTestCase
{
    protected const array ROLES = ['UNSET', AIConversationMessage::ROLE_USER, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::ROLE_SYSTEM];
    protected const array AUTHORS = [null, AIConversationMessage::AUTHOR_USER, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::AUTHOR_TOOL];
    protected const array TYPES = [AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::MESSAGE_TOOL_CALL, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::MESSAGE_ERROR];

    public function testEveryPredicateAnswersItsCombinationsAndNothingElse(): void
    {
        $expectations = [
            'isOwnerOrLegacyUserRoleRow' => static fn (?string $role, ?string $author, string $type): bool => $role === 'USER' && ($author === 'USER' || $author === null),
            'isOwnerOrLegacyUserRoleText' => static fn (?string $role, ?string $author, string $type): bool => $role === 'USER' && ($author === 'USER' || $author === null) && $type === 'TEXT',
            'isOwnerText' => static fn (?string $role, ?string $author, string $type): bool => $role === 'USER' && $author === 'USER' && $type === 'TEXT',
            'isOwnerAuthoredUserRoleRow' => static fn (?string $role, ?string $author, string $type): bool => $role === 'USER' && $author === 'USER',
            'isUserRoleOrOwnerAuthoredText' => static fn (?string $role, ?string $author, string $type): bool => $type === 'TEXT' && ($role === 'USER' || $author === 'USER'),
            'isUserRoleText' => static fn (?string $role, ?string $author, string $type): bool => $role === 'USER' && $type === 'TEXT',
            'isOwnerAuthoredText' => static fn (?string $role, ?string $author, string $type): bool => $type === 'TEXT' && $author === 'USER',
            'isAssistantText' => static fn (?string $role, ?string $author, string $type): bool => $role === 'ASSISTANT' && $type === 'TEXT',
            'isAssistantAuthoredText' => static fn (?string $role, ?string $author, string $type): bool => $type === 'TEXT' && $author === 'ASSISTANT',
            'isSignalOrUserRoleRow' => static fn (?string $role, ?string $author, string $type): bool => $type === 'SIGNAL' || $role === 'USER',
        ];
        self::assertSame(['USER', 'ASSISTANT', 'SYSTEM'], [AIConversationMessage::ROLE_USER, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::ROLE_SYSTEM], 'the matrix spells the constants');
        foreach (self::ROLES as $role) {
            foreach (self::AUTHORS as $author) {
                foreach (self::TYPES as $type) {
                    $message = $this->row($role, $author, $type);
                    foreach ($expectations as $predicate => $expected) {
                        self::assertSame(
                            $expected($role === 'UNSET' ? null : $role, $author, $type),
                            $message->$predicate(),
                            sprintf('%s on role %s, author %s, type %s', $predicate, $role, $author ?? 'null', $type)
                        );
                    }
                }
            }
        }
    }

    protected function row(string $role, ?string $author, string $type): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        if ($role !== 'UNSET') {
            $message->role = $role;
        }
        $message->authorType = $author;
        $message->messageType = $type;
        return $message;
    }
}
