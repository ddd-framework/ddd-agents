<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One item of a connected drive with what the CloudDrive EXTERNAL space mirrors: kind (native document or plain
 * file), revision, the provider's own rights of the token's identity.
 */
class AgentConnectionDriveItem extends ValueObject
{
    public const string KIND_FOLDER = 'FOLDER';
    public const string KIND_FILE = 'FILE';
    public const string KIND_GOOGLE_DOC = 'GOOGLE_DOC';
    public const string KIND_GOOGLE_SHEET = 'GOOGLE_SHEET';
    public const string KIND_GOOGLE_SLIDES = 'GOOGLE_SLIDES';
    /** @var string another native type (form, drawing, …): listed, never read */
    public const string KIND_GOOGLE_OTHER = 'GOOGLE_OTHER';

    public const array KIND_BY_MIME_TYPE = [
        'application/vnd.google-apps.folder' => self::KIND_FOLDER,
        'application/vnd.google-apps.document' => self::KIND_GOOGLE_DOC,
        'application/vnd.google-apps.spreadsheet' => self::KIND_GOOGLE_SHEET,
        'application/vnd.google-apps.presentation' => self::KIND_GOOGLE_SLIDES,
    ];

    public string $itemId = '';
    public string $name = '';
    public string $mimeType = '';
    public string $kind = self::KIND_FILE;
    /** @var string[] */
    public array $parentIds = [];
    public ?int $sizeBytes = null;
    public ?string $md5Checksum = null;
    /** @var string|null Google's head revision (binary files); native documents carry {@see $version} */
    public ?string $headRevisionId = null;
    public ?string $version = null;
    public ?DateTime $modifiedAt = null;
    public ?string $webViewLink = null;
    public ?string $driveId = null;
    public bool $isTrashed = false;
    public bool $canEdit = false;
    public bool $canComment = false;
    public bool $canShare = false;
    public bool $canTrash = false;
    public bool $canManageMembers = false;

    public static function kindOfMimeType(string $mimeType): string
    {
        if (isset(self::KIND_BY_MIME_TYPE[$mimeType])) {
            return self::KIND_BY_MIME_TYPE[$mimeType];
        }
        return str_starts_with($mimeType, 'application/vnd.google-apps.') ? self::KIND_GOOGLE_OTHER : self::KIND_FILE;
    }

    public function isFolder(): bool
    {
        return $this->kind === self::KIND_FOLDER;
    }

    public function isNativeDocument(): bool
    {
        return in_array($this->kind, [self::KIND_GOOGLE_DOC, self::KIND_GOOGLE_SHEET, self::KIND_GOOGLE_SLIDES], true);
    }

    /** The revision a write is locked on: the head revision of a binary, the version of a native document */
    public function getRevisionKey(): string
    {
        return (string)($this->headRevisionId ?? $this->version ?? '');
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->itemId);
    }
}
