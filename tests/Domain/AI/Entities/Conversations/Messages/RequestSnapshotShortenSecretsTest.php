<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The debug capture of the outgoing request must show WHICH credential was sent, never the credential itself.
 */
class RequestSnapshotShortenSecretsTest extends UnitTestCase
{
    public function testEveryEgressAuthShapeIsShortened(): void
    {
        $openRouterKey = 'sk-or-v1-' . str_repeat('a', 60) . 'z9y8';
        $requestPayload = [
            'headers' => [
                'Authorization' => 'Bearer ' . $openRouterKey,
                'x-litellm-api-key' => 'litellm-' . str_repeat('b', 30) . 'k7k7',
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'model' => 'openai/gpt-6-luna',
                'api_key' => $openRouterKey,
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ],
        ];

        $shortenedPayload = RequestSnapshot::shortenSecretsInPayload($requestPayload);
        $encodedPayload = (string)json_encode($shortenedPayload);

        self::assertSame('Bearer sk-or-v1-…z9y8', $shortenedPayload['headers']['Authorization']);
        self::assertSame('litellm-b…k7k7', $shortenedPayload['headers']['x-litellm-api-key']);
        self::assertSame('sk-or-v1-…z9y8', $shortenedPayload['body']['api_key']);
        self::assertStringNotContainsString(str_repeat('a', 20), $encodedPayload);
        self::assertStringNotContainsString(str_repeat('b', 20), $encodedPayload);
        // non-secret parts stay untouched
        self::assertSame('application/json', $shortenedPayload['headers']['Content-Type']);
        self::assertSame($requestPayload['body']['messages'], $shortenedPayload['body']['messages']);
    }

    public function testShortValuesAndPayloadsWithoutAuthAreSafe(): void
    {
        self::assertSame('Bearer …', RequestSnapshot::shortenSecret('Bearer short'));
        self::assertSame('…', RequestSnapshot::shortenSecret('tiny'));

        $payloadWithoutAuth = ['body' => ['model' => 'x', 'messages' => []]];
        self::assertSame($payloadWithoutAuth, RequestSnapshot::shortenSecretsInPayload($payloadWithoutAuth));
    }
}
