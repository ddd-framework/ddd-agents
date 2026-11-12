<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

/**
 * The ONE tone list of the composable kit (the frontend twin is
 * `widgets/ai/ComposedViewConversationWidget/composed-view-tones.ts`): the judgement or role a `Text`, a `Badge`, a
 * value (`KpiTile`, `TableCell`), an `Icon` or a `Map` status line takes. Every `tone` prop accepts the same words, so a
 * model that learned one uses it everywhere. The words some nodes accepted before the list existed stay accepted on the
 * wire ({@see self::ACCEPTED_WORDS}, the props' allowed values on both tree readers) and read as their tone
 * ({@see self::ALIASES}). Not a node class — the node registry reads only `*ComposedViewNode.php`.
 */
class ComposedViewTone
{
    /** the normal text colour */
    public const string NEUTRAL = 'neutral';
    /** secondary text */
    public const string MUTED = 'muted';
    /** good: green */
    public const string SUCCESS = 'success';
    /** attention: amber */
    public const string WARNING = 'warning';
    /** bad: red */
    public const string DANGER = 'danger';
    /** a neutral highlight: blue */
    public const string INFO = 'info';

    /** @var string[] the tones, in the order the model spec lists them */
    public const array TONES = [self::NEUTRAL, self::MUTED, self::SUCCESS, self::WARNING, self::DANGER, self::INFO];

    /** @var string[] the older words a tone prop keeps accepting: `default` (Text), `positive` / `negative` (Map status lines) */
    public const array ALIAS_WORDS = ['default', 'positive', 'negative'];

    /** @var string[] the tone each older word reads as, in the order of {@see self::ALIAS_WORDS} */
    public const array ALIAS_TONES = [self::NEUTRAL, self::SUCCESS, self::DANGER];

    /** @var string[] every word a `tone` prop accepts: the tones and the older words */
    public const array ACCEPTED_WORDS = [...self::TONES, ...self::ALIAS_WORDS];

    /** One line for a prop description: what the model may write. */
    public const string DESCRIPTION = 'neutral (default), muted (secondary text), success (green), warning (amber), danger (red) or info (blue)';

}
