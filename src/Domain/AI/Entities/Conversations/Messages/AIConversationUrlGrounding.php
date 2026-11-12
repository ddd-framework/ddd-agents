<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Common\Entities\WebContent\WebsiteHostName;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;

/**
 * Whether a URL the model passes REACHED it through a tool result or the owner's words, and the platform-verified
 * profile URL a retyped social link is corrected to. No write is refused on this
 * check: a proposal tool of the application uses it to pick the candidates it runs a discovery for, a social-links
 * write to correct a retyped URL of a verified network. Outside the agent loop (no conversation) every URL passes.
 * Pure over the provenance's message set.
 */
class AIConversationUrlGrounding
{
    public static function isUrlGrounded(AgentToolCallProvenance $callProvenance, string $url): bool
    {
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if ($messages === null || $callProvenance->isEvalHarnessCall) {
            return true;
        }
        $needle = self::normalizeUrl($url);
        if ($needle === '') {
            return true;
        }
        foreach ($messages->getElements() as $message) {
            foreach (self::collectEvidenceHaystacks($message) as $haystack) {
                if (self::containsUrlOnABoundary(self::normalizeText($haystack), $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The needle followed by nothing, a separator or a quote — never by more handle characters: "youtube.com/@example"
     * is NOT grounded by a page read that verified "youtube.com/@example1234" (otherwise a shortened handle would be
     * persisted).
     */
    public static function containsUrlOnABoundary(string $normalizedHaystack, string $normalizedNeedle): bool
    {
        // a dot continues the handle only when a letter or digit follows ("@studio.koeln"); a sentence-ending
        // dot after the handle is punctuation ("… über Instagram @studio.koeln.")
        return preg_match('/' . preg_quote($normalizedNeedle, '/') . '(?![\p{L}\p{N}_\-@]|\.[\p{L}\p{N}])/u', $normalizedHaystack) === 1;
    }

    /**
     * The platform-verified profile URL of a network (the page read's "Platform-verified social profile URLs" line of
     * this conversation): with several, the one the given URL is a prefix of (a shortened handle), else the first.
     * Null without a verified URL for the network, or outside the agent loop.
     */
    public static function findPlatformVerifiedSocialProfileUrl(AgentToolCallProvenance $callProvenance, string $network, string $givenUrl, array $ownSiteHosts = []): ?string
    {
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if ($messages === null) {
            return null;
        }
        $verifiedUrls = self::collectOwnSiteVerifiedSocialProfileUrls($messages, $network, $ownSiteHosts);
        if ($verifiedUrls === []) {
            return null;
        }
        $givenNeedle = self::normalizeUrl($givenUrl);
        foreach ($verifiedUrls as $verifiedUrl) {
            if ($givenNeedle !== '' && str_starts_with(self::normalizeUrl($verifiedUrl), $givenNeedle)) {
                return $verifiedUrl;
            }
        }
        // no prefix relation: a correction only when the own site carries exactly ONE profile of this network —
        // never a guess across handles
        return count($verifiedUrls) === 1 ? $verifiedUrls[0] : null;
    }

    /**
     * The verified profile URLs of one network from page reads of the space's OWN site only (the stored website's
     * host, or a host the owner named) — a page of another site never corrects the space's links. Without any own-site
     * host known, every page read counts (the earlier behaviour).
     *
     * @param string[] $ownSiteHosts lower-case hosts without www.
     * @return string[]
     */
    public static function collectOwnSiteVerifiedSocialProfileUrls(AIConversationMessages $messages, string $network, array $ownSiteHosts): array
    {
        $network = strtolower($network);
        $urls = [];
        foreach ($messages->getElements() as $message) {
            if (!isset($message->toolCall) || !$message->toolCall->isResolved()) {
                continue;
            }
            if ($ownSiteHosts !== []) {
                $pageHosts = [];
                foreach ((array)($message->toolCall->arguments['urls'] ?? []) + [(string)($message->toolCall->arguments['url'] ?? '')] as $pageUrl) {
                    $pageHost = self::hostOfUrl((string)$pageUrl);
                    if ($pageHost !== '') {
                        $pageHosts[] = $pageHost;
                    }
                }
                if ($pageHosts !== [] && array_intersect($pageHosts, $ownSiteHosts) === []) {
                    continue; // a page of another site (a third party, a directory)
                }
            }
            $single = AIConversationMessages::newInstance();
            $single->add($message);
            foreach ($single->buildToolCallHistory()->getPlatformVerifiedSocialProfileUrls()?->getUrlsOfNetwork($network) ?? [] as $url) {
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }
        return $urls;
    }

    /** Whether the URL (or its bare handle) stands in an OWNER message — the owner's own words, never a tool result. */
    public static function isUrlInOwnerWords(AgentToolCallProvenance $callProvenance, string $url): bool
    {
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if ($messages === null) {
            return false;
        }
        $normalizedUrl = self::normalizeUrl($url);
        if ($normalizedUrl === '') {
            return false;
        }
        $handle = trim((string)strrchr($normalizedUrl, '/'), '/@');
        foreach ($messages->getElements() as $message) {
            if (!$message->isOwnerAuthoredInbound()) {
                continue;
            }
            $haystack = self::normalizeText((string)($message->message ?? ''));
            if (self::containsUrlOnABoundary($haystack, $normalizedUrl) || ($handle !== '' && mb_strlen($handle) >= 4 && str_contains($haystack, '@' . $handle))) {
                return true;
            }
        }
        return false;
    }

    /** The lower-case host of a URL without www.; '' when none ({@see WebsiteHostName}). */
    public static function hostOfUrl(string $url): string
    {
        return WebsiteHostName::fromUrl($url)->host;
    }

    /**
     * The texts of ONE message a value may have reached the model through — exactly what the wire renders: the tool
     * result and its appendix, a delegation's settled VERDICTS (a background page read's result lives there, not in
     * the delegate row's own result — a verified e-mail was refused as "not grounded" for that), the owner's words
     * and the signals. Never the delegation task labels.
     *
     * @return string[]
     */
    public static function collectEvidenceHaystacks(AIConversationMessage $message): array
    {
        $haystacks = [];
        if (isset($message->toolCall)) {
            $haystacks[] = (string)($message->toolCall->result ?? '');
            $haystacks[] = (string)($message->toolCall->resultAppendix ?? '');
            foreach ($message->getDelegationVerdictTexts() as $verdict) {
                $haystacks[] = $verdict;
            }
        }
        if ($message->isSignalOrUserRoleRow()) {
            $haystacks[] = (string)($message->message ?? '');
        }
        return array_values(array_filter($haystacks, static fn (string $haystack): bool => $haystack !== ''));
    }

    /** Lower-case host + path without scheme, "www.", a trailing slash and JSON escaping. */
    public static function normalizeUrl(string $url): string
    {
        $candidate = mb_strtolower(trim($url));
        $candidate = preg_replace('~^https?://~', '', $candidate) ?? $candidate;
        $candidate = preg_replace('~^www\.~', '', $candidate) ?? $candidate;
        return rtrim($candidate, '/');
    }

    protected static function normalizeText(string $text): string
    {
        $text = str_replace(['\\/', 'https://', 'http://', 'www.'], ['/', '', '', ''], mb_strtolower($text));
        return $text;
    }
}
