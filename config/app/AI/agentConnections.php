<?php

declare(strict_types=1);

use DDD\Domain\AI\Services\Connections\Adapters\Fake\FakeAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\Google\GoogleAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\Imap\ImapSmtpAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SlackAgentConnectionAdapter;

/**
 * AgentConnections (read as `AI.agentConnections`): an account connects its own services (mode 1, ACCOUNT_GRANTED),
 * and the agent takes part with an identity of its own (mode 2, AGENT_OWNED). An application replaces this file as a
 * whole. No secret is written here: every secret is named by the environment variable that holds it.
 *
 * Switched off (`enabled` false): no tool, no skill, no required table, and every route answers 403
 * feature_not_available. Every adapter is off by default as well.
 */
return [
    'enabled' => false,
    // the stage this process runs in; a connection created in another stage is inert here (a production row copied
    // into a dev database can neither read nor send). The value of this environment variable, else 'local'.
    'stageEnvironmentVariable' => 'APP_STAGE',
    // the stage in which sends to EXTERNAL recipients leave without the allow-list
    'productionStage' => 'prod',
    // outside production a send goes only to these addresses (comma-separated, lower case) and fails closed
    'nonProductionAllowedRecipientsEnvironmentVariable' => 'AGENT_CONNECTIONS_NON_PROD_ALLOWED_RECIPIENTS',
    // the key the credentials are encrypted with at rest (AES-256-CBC through DDD\Infrastructure\Libs\Encrypt); one
    // key per stage, so a copied row cannot even be decrypted
    'credentialsKeyEnvironmentVariable' => 'AGENT_CONNECTIONS_CREDENTIALS_KEY',
    // the public base URL the OAuth redirect URIs are built on (…/api/admin/agent-connections/oauth/<provider>/callback)
    'publicBaseUrlEnvironmentVariable' => 'PUBLIC_URL',
    // where the browser lands after a connect (relative to the public base URL)
    'settingsPagePath' => '/admin/agent/connections',
    // recipients of these e-mail domains count as the team (a standing rule may reach them without a yes); empty = none
    'internalEmailDomains' => [],
    /** env-file values starting with one of these prefixes are unresolved secret references and count as unset */
    'unresolvedSecretReferencePrefixes' => [],
    'adapters' => [
        // provider => class; an application registers further adapters here
        'classes' => [
            'GOOGLE' => GoogleAgentConnectionAdapter::class,
            'IMAP' => ImapSmtpAgentConnectionAdapter::class,
            'SLACK' => SlackAgentConnectionAdapter::class,
            'FAKE' => FakeAgentConnectionAdapter::class,
        ],
        'GOOGLE' => [
            'enabled' => false,
            'clientIdEnvironmentVariable' => 'AGENT_CONNECTIONS_GOOGLE_CLIENT_ID',
            'clientSecretEnvironmentVariable' => 'AGENT_CONNECTIONS_GOOGLE_CLIENT_SECRET',
            // the account's Google Workspace domains that may connect; empty = any
            'allowedHostedDomains' => [],
        ],
        'IMAP' => [
            'enabled' => false,
        ],
        'SLACK' => [
            'enabled' => false,
            'botTokenEnvironmentVariable' => 'AGENT_CONNECTIONS_SLACK_BOT_TOKEN',
            'signingSecretEnvironmentVariable' => 'AGENT_CONNECTIONS_SLACK_SIGNING_SECRET',
            // the app-level token (xapp-…) of Socket Mode; only locally and on dev
            'appTokenEnvironmentVariable' => 'AGENT_CONNECTIONS_SLACK_APP_TOKEN',
            // EVENTS_API (production: HTTPS endpoint) or SOCKET_MODE (local and dev: a long-running command)
            'inboundMode' => 'EVENTS_API',
            // the one fixed line an unknown Slack user gets; no model call
            'unknownUserLine' => 'Hi! I only talk to linked team members. Please ask an admin to link your Slack user.',
        ],
        // the test double of evals and unit tests; never reaches a provider
        'FAKE' => [
            'enabled' => false,
        ],
    ],
    // the defaults of a new connection ; each connection may lower them
    'limits' => [
        'sendsPerHour' => 20,
        'sendsPerDay' => 100,
        'sendsPerRecipientPerDay' => 10,
        'inboundRunsPerSenderPerHour' => 5,
        // consecutive provider errors after which a connection pauses itself
        'pauseAfterConsecutiveErrors' => 5,
    ],
];
