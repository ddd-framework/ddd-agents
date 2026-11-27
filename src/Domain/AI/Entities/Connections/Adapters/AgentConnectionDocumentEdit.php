<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One edit of a native document as the agent states it, against an anchor of its structured read: replace the text of
 * a paragraph or cell, insert a paragraph after it, delete it, or replace a text everywhere.
 */
class AgentConnectionDocumentEdit extends ValueObject
{
    public const string ACTION_REPLACE = 'REPLACE';
    public const string ACTION_INSERT_AFTER = 'INSERT_AFTER';
    public const string ACTION_DELETE = 'DELETE';
    public const string ACTION_REPLACE_ALL = 'REPLACE_ALL';
    public const array ACTIONS = [self::ACTION_REPLACE, self::ACTION_INSERT_AFTER, self::ACTION_DELETE, self::ACTION_REPLACE_ALL];

    #[Choice(choices: self::ACTIONS)]
    public string $action = self::ACTION_REPLACE;
    /** @var string The anchor of the read (`p:12`, `tbl:2 r3c1`, `nr:Summary`, `s:3 obj:g12ab`); empty for REPLACE_ALL */
    public string $anchor = '';
    /** @var string The new text (REPLACE, INSERT_AFTER, REPLACE_ALL) */
    public string $text = '';
    /** @var string|null REPLACE_ALL: the text to find (case-sensitive) */
    public ?string $findText = null;
}
