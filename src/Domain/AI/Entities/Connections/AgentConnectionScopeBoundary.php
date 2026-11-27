<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/** One boundary of a scope inside the external account: an allowed drive folder, mailbox label, calendar, contact label or contact source */
class AgentConnectionScopeBoundary extends ValueObject
{
    public const string KIND_FOLDER = 'FOLDER';
    public const string KIND_LABEL = 'LABEL';
    public const string KIND_CALENDAR = 'CALENDAR';
    public const string KIND_CONTACT_LABEL = 'CONTACT_LABEL';
    public const string KIND_CONTACT_SOURCE = 'CONTACT_SOURCE';
    public const string KIND_CHANNEL = 'CHANNEL';
    public const array KINDS = [self::KIND_FOLDER, self::KIND_LABEL, self::KIND_CALENDAR, self::KIND_CONTACT_LABEL, self::KIND_CONTACT_SOURCE, self::KIND_CHANNEL];

    /** @var string FOLDER, LABEL, CALENDAR, CONTACT_LABEL or CONTACT_SOURCE */
    #[Choice(choices: self::KINDS)]
    public string $kind = self::KIND_FOLDER;

    /** @var string The provider's id or name of the folder, label or calendar */
    #[Length(max: 500)]
    public string $value = '';

    public static function of(string $kind, string $value): static
    {
        $scopeBoundary = static::newInstance();
        $scopeBoundary->kind = strtoupper(trim($kind));
        $scopeBoundary->value = trim($value);
        return $scopeBoundary;
    }

    /** A boundary of a known kind with a value: anything else cannot be read and limits to nothing */
    public function isReadable(): bool
    {
        return in_array($this->kind, static::KINDS, true) && trim($this->value) !== '';
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->kind . ':' . $this->value);
    }
}
