<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * Who wrote a widget block: the MODEL (the block carries no `emittedBy` field — every block the model
 * wrote or copied from a dictation, and every stored block from before the field), a TOOL (`emittedBy: tool:<toolName>`)
 * or the PLATFORM (`emittedBy: platform:<reason>`). Read from the block ({@see WidgetBlock::getOrigin()}), written only
 * by the one writer ({@see ConversationWidget::buildWidgetBlockEmittedBy()}). The AI core holds no platform reasons: a
 * reason is a constant of the domain that writes the widget. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ConversationWidgetOrigin extends ValueObject
{
    /** @var string The block field that carries the origin — the last field of the block's JSON object. */
    public const string EMITTED_BY_FIELD = 'emittedBy';

    public const string TYPE_MODEL = 'model';
    public const string TYPE_TOOL = 'tool';
    public const string TYPE_PLATFORM = 'platform';

    /** @var string The prefix of a tool origin's `emittedBy` value. */
    public const string TOOL_PREFIX = self::TYPE_TOOL . ':';

    /** @var string The prefix of a platform origin's `emittedBy` value. */
    public const string PLATFORM_PREFIX = self::TYPE_PLATFORM . ':';

    #[Choice(choices: [self::TYPE_MODEL, self::TYPE_TOOL, self::TYPE_PLATFORM])]
    public string $type = self::TYPE_MODEL;

    /** @var string|null The tool whose call wrote the block; null unless TOOL. */
    public ?string $toolName = null;

    /** @var string|null Why the platform wrote a widget no tool call asked for (the writing domain's constant); null unless PLATFORM. */
    public ?string $platformReason = null;

    /** A block without the field — written by the model. */
    public static function model(): static
    {
        return new static();
    }

    /** A block a tool call wrote; a blank tool name is no origin and reads as the model. */
    public static function tool(string $toolName): static
    {
        $conversationWidgetOrigin = new static();
        if (trim($toolName) !== '') {
            $conversationWidgetOrigin->type = self::TYPE_TOOL;
            $conversationWidgetOrigin->toolName = $toolName;
        }
        return $conversationWidgetOrigin;
    }

    /** A widget the platform wrote on its own; a blank reason is no origin and reads as the model. */
    public static function platform(string $platformReason): static
    {
        $conversationWidgetOrigin = new static();
        if (trim($platformReason) !== '') {
            $conversationWidgetOrigin->type = self::TYPE_PLATFORM;
            $conversationWidgetOrigin->platformReason = $platformReason;
        }
        return $conversationWidgetOrigin;
    }

    /**
     * The origin a block's `emittedBy` value names: `tool:<toolName>` or `platform:<reason>`. Null (no field), a
     * non-string, a value without one of the two prefixes or with nothing after it read as the model — no fallback to
     * the row's `signalSource`.
     */
    public static function fromEmittedByValue(mixed $emittedByValue): self
    {
        if (!is_string($emittedByValue)) {
            return self::model();
        }
        if (str_starts_with($emittedByValue, self::TOOL_PREFIX)) {
            return self::tool(substr($emittedByValue, strlen(self::TOOL_PREFIX)));
        }
        if (str_starts_with($emittedByValue, self::PLATFORM_PREFIX)) {
            return self::platform(substr($emittedByValue, strlen(self::PLATFORM_PREFIX)));
        }
        return self::model();
    }

    /** The block's `emittedBy` value (`tool:<toolName>` / `platform:<reason>`); null for the model, whose blocks carry no field. */
    public function toEmittedByValue(): ?string
    {
        return match ($this->type) {
            self::TYPE_TOOL => self::TOOL_PREFIX . $this->toolName,
            self::TYPE_PLATFORM => self::PLATFORM_PREFIX . $this->platformReason,
            default => null,
        };
    }

    /**
     * The origin as an inspector shows it: the block's `emittedBy` value (`tool:<toolName>` / `platform:<reason>`), or
     * `model` for a block without the field.
     */
    public function toDisplayLabel(): string
    {
        return $this->toEmittedByValue() ?? self::TYPE_MODEL;
    }

    /**
     * The `signalSource` of the row the writer stores for this origin: the WRITER label, never read as
     * an origin — a tool row keeps the tool name (today's column bytes), a platform row carries the same
     * `platform:<reason>` its block names. Null for the model, which writes no tool row.
     */
    public function toRowLabel(): ?string
    {
        return match ($this->type) {
            self::TYPE_TOOL => $this->toolName,
            self::TYPE_PLATFORM => $this->toEmittedByValue(),
            default => null,
        };
    }

    public function isModel(): bool
    {
        return $this->type === self::TYPE_MODEL;
    }

    public function isTool(): bool
    {
        return $this->type === self::TYPE_TOOL;
    }

    public function isPlatform(): bool
    {
        return $this->type === self::TYPE_PLATFORM;
    }
}
