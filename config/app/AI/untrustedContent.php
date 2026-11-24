<?php

declare(strict_types=1);

/**
 * Untrusted content in the agent's tool results — the package default, read as `Config::get('AI.untrustedContent')`.
 * An application replaces this file whole with its own `config/app/AI/untrustedContent.php` (keys are never merged).
 *
 * A READ or WORKSPACE tool returns UNTRUSTED content unless it (or its package) declares
 * `#[MCPToolReturnsTrustedContent]` or is listed here ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolContentTrust}).
 * After a call of such a tool completed in the current owner turn, the executor can hold later calls:
 *
 * `writeAfterUntrustedRead` (a WRITE or DELETE tool):
 * - `off` (default): the write runs as any write;
 * - `ownerConsent`: the write runs only after the owner-consent check finds the owner's explicit yes to this step
 *   (decision site `ownerConsent` must be `on`; otherwise, in a scheduled run or in a delegated sub-task the write is
 *   refused with a clear tool result);
 * - `refuse`: the write is refused for the rest of the turn; the owner starts it with a new message.
 * A missing value is `off`; an unknown value is `off` too and is reported once per process in the issues log.
 *
 * `unseenUrlAfterUntrustedRead` (a tool declaring `#[MCPToolFetchesModelChosenUrl]`):
 * - `refuse` (default): a URL whose query string or fragment appears in no owner message and no tool result is refused;
 * - `off`: the fetch runs as any fetch.
 *
 * `trustedPackages`: MCPPackage classes (an application's subclass matches its parent) whose tools return only content
 * the platform vouches for — for packages the application cannot annotate.
 * `trustedTools`: tool names with the same meaning.
 */
return [
    'writeAfterUntrustedRead' => 'off',
    'unseenUrlAfterUntrustedRead' => 'refuse',
    'trustedPackages' => [],
    'trustedTools' => [],
];
