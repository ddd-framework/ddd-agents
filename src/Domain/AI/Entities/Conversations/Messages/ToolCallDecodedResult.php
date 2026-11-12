<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A stored tool-call result decoded ONCE: the json-encoded
 * {@see \DDD\Presentation\Mcp\Dtos\MCPToolResponse} (`content[]`, `structuredContent`, `preservedFacts`) or a plain
 * text (a corrective notice, a refusal). Each reading keeps today's rules exactly:
 * - the content text joins the trimmed `content[].text` parts, or is the whole trimmed string when it is no such JSON;
 * - the structured content also resolves a legacy row whose JSON object is followed by a plain-text hint;
 * - the preserved facts come only from a result that decodes whole.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallDecodedResult extends ValueObject
{
    /** The structuredContent outcome a tool declares when it ran but SAVED NOTHING. */
    public const string OUTCOME_INCOMPLETE = 'incomplete';

    /** The words the tool rendered: the `content[].text` parts joined by "\n", or the plain result; '' without one. */
    public string $contentText = '';

    /** @var array<string, mixed>|null The `structuredContent` object, null when the result carries none. */
    public ?array $structuredContent = null;

    /** @var array<string, int|float|string>|null The platform's `preservedFacts`, null when absent or empty. */
    public ?array $preservedFacts = null;

    /** @var bool The result JSON says `isError` (the executor's error flag); false for a plain text. */
    public bool $isError = false;

    /** The content text of a result the executor returned in its structured shape: `Successful response for '<tool>'`. */
    public const string STRUCTURED_SHAPE_RESULT_TEXT_PREFIX = 'Successful response for \'';

    public static function fromResultText(?string $resultText): static
    {
        $decodedResult = new static();
        $rawText = (string)($resultText ?? '');
        $trimmedText = trim($rawText);
        if ($trimmedText === '') {
            return $decodedResult;
        }
        $wholeDecode = json_decode($rawText, true);
        $decodedResult->contentText = self::readContentText($wholeDecode, $trimmedText);
        $decodedResult->structuredContent = self::readStructuredContent($wholeDecode, $rawText);
        $decodedResult->preservedFacts = self::readPreservedFacts($wholeDecode, $rawText);
        $decodedResult->isError = is_array($wholeDecode) && ($wholeDecode['isError'] ?? false) === true;
        return $decodedResult;
    }

    protected static function readContentText(mixed $wholeDecode, string $trimmedText): string
    {
        if (!is_array($wholeDecode) || !isset($wholeDecode['content']) || !is_array($wholeDecode['content'])) {
            return $trimmedText;
        }
        $textParts = [];
        foreach ($wholeDecode['content'] as $contentPart) {
            if (is_array($contentPart) && ($contentPart['type'] ?? null) === 'text' && is_string($contentPart['text'] ?? null)) {
                $textParts[] = trim($contentPart['text']);
            }
        }
        return trim(implode("\n", $textParts));
    }

    /** @return array<string, mixed>|null */
    protected static function readStructuredContent(mixed $wholeDecode, string $rawText): ?array
    {
        $decoded = $wholeDecode;
        if (!is_array($decoded) && str_starts_with(ltrim($rawText), '{')) {
            // Legacy rows (before ToolCall::$resultAppendix) carry a plain-text hint AFTER the JSON object — decode the
            // object up to its last closing brace so their conventions still resolve on re-reads.
            $lastBracePosition = strrpos($rawText, '}');
            if ($lastBracePosition !== false) {
                $decoded = json_decode(substr($rawText, 0, $lastBracePosition + 1), true);
            }
        }
        $structuredContent = is_array($decoded) ? ($decoded['structuredContent'] ?? null) : null;
        return is_array($structuredContent) ? $structuredContent : null;
    }

    /** @return array<string, int|float|string>|null */
    protected static function readPreservedFacts(mixed $wholeDecode, string $rawText): ?array
    {
        if (!str_contains($rawText, '"preservedFacts"')) {
            return null;
        }
        $preservedFacts = is_array($wholeDecode) ? ($wholeDecode['preservedFacts'] ?? null) : null;
        return is_array($preservedFacts) && $preservedFacts !== [] ? $preservedFacts : null;
    }

    /**
     * Whether the executor returned this result in its STRUCTURED shape — its `structuredContent` is the serialized
     * result DTO (a markdown-rendered result's `structuredContent` holds only the loop-control fields).
     */
    public function isStructuredShapeResult(): bool
    {
        return $this->structuredContent !== null && str_starts_with($this->contentText, self::STRUCTURED_SHAPE_RESULT_TEXT_PREFIX);
    }

    /** The content text cut to `$maxChars` from the front. */
    public function getContentTextHead(int $maxChars): string
    {
        return mb_strlen($this->contentText) > $maxChars ? mb_substr($this->contentText, 0, $maxChars) : $this->contentText;
    }

    /**
     * Whether the result declares that the call ran but saved nothing: `outcome: incomplete`, or `applied: false` (a
     * mutation result's and a consent-refused write's way of saying "rejected, nothing written").
     */
    public function declaresIncompleteOutcome(): bool
    {
        if ($this->structuredContent === null) {
            return false;
        }
        return ($this->structuredContent['outcome'] ?? null) === self::OUTCOME_INCOMPLETE
            || ($this->structuredContent['applied'] ?? null) === false;
    }
}
