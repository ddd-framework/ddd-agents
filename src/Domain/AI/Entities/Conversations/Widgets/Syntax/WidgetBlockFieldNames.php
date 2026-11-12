<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Infrastructure\Services\DDDService;

/**
 * The names an application chooses for what a widget block says about its agent space: the block field that carries the
 * widget's self-hydration key ({@see \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace::getWidgetSelfHydrationKey()})
 * and the suffix the client appends to the owner's text for a click on the widget of another space. The values are
 * part of the stored rows and of the client's contract, so an application that has rows and a client declares its
 * own in a class at the same path (it extends this one and redeclares the constants); every writer and reader of a
 * block asks the getters, never the constants. Reads nothing.
 */
class WidgetBlockFieldNames
{
    /** @var string The block field that carries the widget's self-hydration key; the default key is the id of the space. */
    public const string WIDGET_SELF_HYDRATION_KEY = 'agentSpaceId';

    /**
     * @var string|null The regular expression of the suffix a click on the widget of another space appends to the
     * picked label(s), anchored at the end of the text, its first group the key of that space; null = the client
     * appends none.
     */
    public const ?string OWNER_TEXT_SUFFIX_PATTERN_OF_ANOTHER_SPACE = null;

    /**
     * The class that declares the names: the application's class at the same path, else this one.
     *
     * @return class-string<WidgetBlockFieldNames>
     */
    public static function getFieldNamesClassOfTheApplication(): string
    {
        /** @var class-string<WidgetBlockFieldNames> $fieldNamesClassOfTheApplication */
        $fieldNamesClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $fieldNamesClassOfTheApplication;
    }

    /** The block field that carries the widget's self-hydration key, as the application names it. */
    public static function getWidgetSelfHydrationKeyFieldName(): string
    {
        return (self::getFieldNamesClassOfTheApplication())::WIDGET_SELF_HYDRATION_KEY;
    }

    /** The suffix pattern of a click on the widget of another space, as the application declares it; null = none. */
    public static function getOwnerTextSuffixPatternOfAnotherSpace(): ?string
    {
        return (self::getFieldNamesClassOfTheApplication())::OWNER_TEXT_SUFFIX_PATTERN_OF_ANOTHER_SPACE;
    }
}
