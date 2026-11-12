<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

/**
 * The categories a `ComposedView` node type belongs to — what a slot accepts, like HTML's content model:
 * `inline` (Text, Badge, Link, EntityLink, Icon, SourceRef), `block` (Title, Markdown, List, Quote, Divider), `data`
 * (KpiTile, Chart, Table), `media` (Image, ImageGroup, Video), `input` (Tabs, Select, Slider, Checkbox, Button),
 * `layout` (Stack, Grid, Surface, Carousel, Popover), `heavy` (Map, Diagram, custom visualization, embedded domain
 * widgets — never in a compact slot).
 */
class ComposedViewNodeCategory
{
    public const string INLINE = 'inline';
    public const string BLOCK = 'block';
    public const string DATA = 'data';
    public const string MEDIA = 'media';
    public const string INPUT = 'input';
    public const string LAYOUT = 'layout';
    public const string HEAVY = 'heavy';

    /** @var string[] every category, in the order the schema lists them */
    public const array ALL = [self::INLINE, self::BLOCK, self::DATA, self::MEDIA, self::INPUT, self::LAYOUT, self::HEAVY];

    /** @var string[] the categories a slot that names none accepts — everything that is not heavy */
    public const array ALL_BUT_HEAVY = [self::INLINE, self::BLOCK, self::DATA, self::MEDIA, self::INPUT, self::LAYOUT];
}
