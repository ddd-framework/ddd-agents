<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The platform-verified social profile URLs that the page reads of ONE message set found, merged per network, with the
 * index of the newest row that carried them. Built by
 * {@see AIConversationToolCallHistory::getPlatformVerifiedSocialProfileUrls()}; the URL grounding corrects a retyped
 * social link to them ({@see AIConversationUrlGrounding::collectOwnSiteVerifiedSocialProfileUrls()}).
 *
 * @phpstan-consistent-constructor
 */
class PlatformVerifiedSocialProfileUrls extends ValueObject
{
    /** @var array<string, string[]> The URLs per lower-case network name, each list in the order found, without duplicates */
    public array $urlsByNetwork = [];

    /** @var int The `indexInConversation` of the newest row that carried a verified line */
    public int $messageIndex = 0;

    /**
     * @param array<string, string[]> $urlsByNetwork
     * @param int $messageIndex
     * @return static
     */
    public static function forUrlsByNetworkAndMessageIndex(array $urlsByNetwork, int $messageIndex): static
    {
        $platformVerifiedSocialProfileUrls = new static();
        $platformVerifiedSocialProfileUrls->urlsByNetwork = $urlsByNetwork;
        $platformVerifiedSocialProfileUrls->messageIndex = $messageIndex;
        return $platformVerifiedSocialProfileUrls;
    }

    /**
     * @param string $network The lower-case network name
     * @return string[] The verified URLs of the network; empty when none was found
     */
    public function getUrlsOfNetwork(string $network): array
    {
        return $this->urlsByNetwork[$network] ?? [];
    }
}
