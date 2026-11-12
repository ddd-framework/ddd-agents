<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Apple's machine identifiers → the human product names the owner would use.
 * The native app knows only the identifier (`iPhone16,2`); the bracket says "iPhone 15 Pro Max". An
 * identifier outside this map is rendered as it is — never guessed. Pure lookup, no I/O.
 */
class OwnerDeviceAppleModels
{
    /** @var array<string, string> identifier → product name */
    public const array NAMES = [
        'iPhone11,2' => 'iPhone XS', 'iPhone11,4' => 'iPhone XS Max', 'iPhone11,6' => 'iPhone XS Max', 'iPhone11,8' => 'iPhone XR',
        'iPhone12,1' => 'iPhone 11', 'iPhone12,3' => 'iPhone 11 Pro', 'iPhone12,5' => 'iPhone 11 Pro Max', 'iPhone12,8' => 'iPhone SE (2nd gen)',
        'iPhone13,1' => 'iPhone 12 mini', 'iPhone13,2' => 'iPhone 12', 'iPhone13,3' => 'iPhone 12 Pro', 'iPhone13,4' => 'iPhone 12 Pro Max',
        'iPhone14,2' => 'iPhone 13 Pro', 'iPhone14,3' => 'iPhone 13 Pro Max', 'iPhone14,4' => 'iPhone 13 mini', 'iPhone14,5' => 'iPhone 13',
        'iPhone14,6' => 'iPhone SE (3rd gen)', 'iPhone14,7' => 'iPhone 14', 'iPhone14,8' => 'iPhone 14 Plus',
        'iPhone15,2' => 'iPhone 14 Pro', 'iPhone15,3' => 'iPhone 14 Pro Max', 'iPhone15,4' => 'iPhone 15', 'iPhone15,5' => 'iPhone 15 Plus',
        'iPhone16,1' => 'iPhone 15 Pro', 'iPhone16,2' => 'iPhone 15 Pro Max',
        'iPhone17,1' => 'iPhone 16 Pro', 'iPhone17,2' => 'iPhone 16 Pro Max', 'iPhone17,3' => 'iPhone 16', 'iPhone17,4' => 'iPhone 16 Plus', 'iPhone17,5' => 'iPhone 16e',
        'iPhone18,1' => 'iPhone 17 Pro', 'iPhone18,2' => 'iPhone 17 Pro Max', 'iPhone18,3' => 'iPhone 17', 'iPhone18,4' => 'iPhone Air',
        'iPad13,1' => 'iPad Air (4th gen)', 'iPad13,2' => 'iPad Air (4th gen)', 'iPad13,16' => 'iPad Air (5th gen)', 'iPad13,17' => 'iPad Air (5th gen)',
        'iPad13,18' => 'iPad (10th gen)', 'iPad13,19' => 'iPad (10th gen)',
        'iPad14,1' => 'iPad mini (6th gen)', 'iPad14,2' => 'iPad mini (6th gen)',
        'iPad14,3' => 'iPad Pro 11" (4th gen)', 'iPad14,4' => 'iPad Pro 11" (4th gen)', 'iPad14,5' => 'iPad Pro 12.9" (6th gen)', 'iPad14,6' => 'iPad Pro 12.9" (6th gen)',
        'iPad14,8' => 'iPad Air 11" (M2)', 'iPad14,9' => 'iPad Air 11" (M2)', 'iPad14,10' => 'iPad Air 13" (M2)', 'iPad14,11' => 'iPad Air 13" (M2)',
        'iPad16,3' => 'iPad Pro 11" (M4)', 'iPad16,4' => 'iPad Pro 11" (M4)', 'iPad16,5' => 'iPad Pro 13" (M4)', 'iPad16,6' => 'iPad Pro 13" (M4)',
    ];

    /** The product name for an identifier, null when the map does not know it (the caller keeps the identifier). */
    public static function humanName(string $identifier): ?string
    {
        return self::NAMES[trim($identifier)] ?? null;
    }
}
