<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages\ToolCallResultDecoding;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The four ToolCall decoders now read ONE ToolCallDecodedResult. The decoders as they stood
 * before (copied verbatim below) are the reference; every shape of a stored result, plus whitespace / trailing-hint /
 * truncation variants of each, must read the same through the new object — and again after `$result` changed.
 */
class ToolCallDecodedResultDifferentialTest extends UnitTestCase
{
    /** @return string[] */
    protected function corpus(): array
    {
        $shapes = [
            '',
            '   ',
            'NOT executed: you already persisted exactly this.',
            '{"content":[{"type":"text","text":"  Saved.  "},{"type":"image","data":"x"},{"type":"text","text":"Second"}],"isError":false}',
            '{"content":[{"type":"text","text":"Blocked"}],"structuredContent":{"outcome":"incomplete"},"isError":false}',
            '{"content":[{"type":"text","text":"Blocked"}],"structuredContent":{"applied":false},"isError":false}',
            '{"content":[{"type":"text","text":"ok"}],"structuredContent":{"applied":true,"outcome":"ADDRESS_OK"},"preservedFacts":{"a.b":"yes","n":3}}',
            '{"content":[{"type":"text","text":"ok"}],"preservedFacts":{}}',
            '{"content":[{"type":"text","text":"x"}],"structuredContent":{"backgroundOperations":[{"tool":"a"}],"turnMinimumSteps":4}}',
            '{"content":[{"type":"text","text":"x"}],"structuredContent":{"delegationTasks":[{"label":"a"}],"delegationSkillNames":["S"],"delegationMaxStepsPerTurn":5,"delegationModelName":"m","delegationAwaitedConversationIds":[1],"turnObligation":""}}',
            '{"structuredContent":"not-an-object","preservedFacts":"nope"}',
            '{"content":"not-a-list","structuredContent":[]}',
            '[1,2,3]',
            '{"broken": ',
            'null',
            '"just a json string"',
        ];
        $corpus = [];
        foreach ($shapes as $shape) {
            $corpus[] = $shape;
            $corpus[] = "\n  " . $shape . "  \n";
            $corpus[] = $shape . "\n\nHint: call load_skills first.";
            $corpus[] = $shape . ' }';
            $corpus[] = substr($shape, 0, (int)floor(strlen($shape) / 2));
        }
        return $corpus;
    }

    public function testEveryReadingEqualsTheDecodersItReplaces(): void
    {
        $toolCall = new ToolCall();
        foreach ($this->corpus() as $resultText) {
            foreach ([$resultText, null] as $storedResult) {
                // the same call object is reused: a changed `$result` must never answer from the previous decode
                $toolCall->result = $storedResult;
                $label = var_export($storedResult, true);
                foreach ([0, 3, 12, 500] as $maxChars) {
                    self::assertSame(self::referenceTextHead($storedResult, $maxChars), $toolCall->getResultTextHead($maxChars), $label . ' head ' . $maxChars);
                }
                self::assertSame(self::referenceStructuredContent($storedResult), $toolCall->getResultStructuredContent(), $label . ' structured');
                self::assertSame(self::referenceDeclaresIncomplete($storedResult), $toolCall->resultDeclaresIncompleteOutcome(), $label . ' incomplete');
                self::assertSame(self::referencePreservedFacts($storedResult), $toolCall->getResultPreservedFacts(), $label . ' facts');
            }
        }
    }

    protected static function referenceTextHead(?string $result, int $maxChars): string
    {
        $resultText = trim((string)($result ?? ''));
        if ($resultText === '') {
            return '';
        }
        $text = $resultText;
        $decodedResult = json_decode($resultText, true);
        if (is_array($decodedResult) && isset($decodedResult['content']) && is_array($decodedResult['content'])) {
            $textParts = [];
            foreach ($decodedResult['content'] as $contentPart) {
                if (is_array($contentPart) && ($contentPart['type'] ?? null) === 'text' && is_string($contentPart['text'] ?? null)) {
                    $textParts[] = trim($contentPart['text']);
                }
            }
            $text = trim(implode("\n", $textParts));
        }
        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) : $text;
    }

    /** @return array<string, mixed>|null */
    protected static function referenceStructuredContent(?string $result): ?array
    {
        $resultText = (string)($result ?? '');
        $decodedResult = json_decode($resultText, true);
        if (!is_array($decodedResult) && str_starts_with(ltrim($resultText), '{')) {
            $lastBracePosition = strrpos($resultText, '}');
            if ($lastBracePosition !== false) {
                $decodedResult = json_decode(substr($resultText, 0, $lastBracePosition + 1), true);
            }
        }
        $structuredContent = is_array($decodedResult) ? ($decodedResult['structuredContent'] ?? null) : null;
        return is_array($structuredContent) ? $structuredContent : null;
    }

    protected static function referenceDeclaresIncomplete(?string $result): bool
    {
        $structuredContent = self::referenceStructuredContent($result);
        if (!is_array($structuredContent)) {
            return false;
        }
        return ($structuredContent['outcome'] ?? null) === 'incomplete' || ($structuredContent['applied'] ?? null) === false;
    }

    /** @return array<string, mixed>|null */
    protected static function referencePreservedFacts(?string $result): ?array
    {
        $resultText = (string)($result ?? '');
        if (!str_contains($resultText, '"preservedFacts"')) {
            return null;
        }
        $decodedResult = json_decode($resultText, true);
        $preservedFacts = is_array($decodedResult) ? ($decodedResult['preservedFacts'] ?? null) : null;
        return is_array($preservedFacts) && $preservedFacts !== [] ? $preservedFacts : null;
    }
}
