<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The body of ONE fence read from a message's text: where it ends, the JSON it carries, whether it is closed and the
 * last line it covers. Built by {@see WidgetFenceSyntaxParser::readFenceBody()}; the fence parsers turn it into the
 * block match ({@see buildBlockSyntaxMatch()}).
 *
 * @phpstan-consistent-constructor
 */
class WidgetFenceBody extends ValueObject
{
    /** @var int The byte offset right after the body (after its closer line when it has one) */
    public int $end = 0;

    /** @var string The text between the opener and the closer (or the balanced JSON object of a repaired fence) */
    public string $widgetJson = '';

    /** @var bool A closer line ends the body, or the repair splices one after its JSON object */
    public bool $isClosed = false;

    /** @var int The index of the last line the body covers */
    public int $lastLineIndex = 0;

    /**
     * @param int $end
     * @param string $widgetJson
     * @param bool $isClosed
     * @param int $lastLineIndex
     * @return static
     */
    public static function forBodyEnd(int $end, string $widgetJson, bool $isClosed, int $lastLineIndex): static
    {
        $widgetFenceBody = new static();
        $widgetFenceBody->end = $end;
        $widgetFenceBody->widgetJson = $widgetJson;
        $widgetFenceBody->isClosed = $isClosed;
        $widgetFenceBody->lastLineIndex = $lastLineIndex;
        return $widgetFenceBody;
    }

    /**
     * The fenced block that starts at `$blockStart` (the opener's first backtick) and ends with this body.
     *
     * @param int $blockStart
     * @return WidgetBlockSyntaxMatch
     */
    public function buildBlockSyntaxMatch(int $blockStart): WidgetBlockSyntaxMatch
    {
        return WidgetBlockSyntaxMatch::forBlock($this->lastLineIndex, $blockStart, $this->end - $blockStart, WidgetBlock::SYNTAX_FENCE, $this->widgetJson, null, $this->isClosed);
    }
}
