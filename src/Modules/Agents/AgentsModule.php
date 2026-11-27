<?php

declare(strict_types=1);

namespace DDD\Modules\Agents;

use DDD\Infrastructure\Modules\DDDModule;
use Ratchet\Client\Connector;

/**
 * The agents module: the agentic loop on top of `ddd-ai` (conversations, messages, compaction, the task plan, skills
 * and MCP metadata, purposes and criteria, widgets and signal sources, delegation, the supervisor, run claims), and the
 * agent's voice channel (calls between the owner and the agent: the call and its store, admission, ring, the provider
 * adapters, the session worker, the routes, the admin list and the tools), switched off by default.
 *
 * The module shares the `DDD\Domain\AI\` and `DDD\Presentation\` roots with `ddd-ai` and the framework, but never a
 * directory: every class sits below one of the namespaces it owns, and no other package has a file there. Owned
 * namespaces ({@see self::OWNED_NAMESPACES}, declared to Core by {@see getOwnedNamespaces()} and checked by Core's
 * `ModuleNamespacesTestCase` and the package's contract test):
 * - `DDD\Domain\AI\Entities\AgentSpaces\`, `…\Entities\ClientTools\`, `…\Entities\Connections\`, `…\Entities\Conversations\`, `…\Entities\Discovery\`, `…\Entities\Documents\`,
 *   `…\Entities\Drives\`, `…\Entities\Environment\`,
 *   `…\Entities\Evals\`, `…\Entities\Installation\`, `…\Entities\MCPPackages\`, `…\Entities\Mandates\`, `…\Entities\Models\CustomerSelection\`,
 *   `…\Entities\RecordedRuns\`, `…\Entities\Skills\`, `…\Entities\SystemHealth\`, `…\Entities\Texts\Embeddings\`, `…\Entities\Voice\`,
 *   `…\Entities\WebSearch\`;
 * - `DDD\Domain\AI\Exceptions\Connections\`, `…\Exceptions\Conversations\`, `…\Exceptions\Evals\`, `…\Exceptions\Texts\Embeddings\`, `…\Exceptions\Voice\`;
 * - `DDD\Domain\AI\Interfaces\`, `DDD\Domain\AI\MessageHandlers\`;
 * - `DDD\Domain\AI\Libs\RecordedRuns\`, `DDD\Domain\AI\Libs\Connections\`, `DDD\Domain\AI\Listeners\Conversations\`,
 *   `DDD\Domain\AI\Listeners\Connections\`, `DDD\Domain\AI\Traits\Connections\`,
 *   `DDD\Domain\AI\Messenger\Conversations\`, `DDD\Domain\AI\Traits\Conversations\`;
 * - `DDD\Domain\AI\Repo\Argus\Conversations\`, `…\Repo\Argus\Guard\`, `…\Repo\Argus\Mandates\`, `…\Repo\Argus\WebSearch\`,
 *   `…\Repo\DB\AgentSpaces\`, `…\Repo\DB\Connections\`, `…\Repo\DB\Conversations\`, `…\Repo\DB\Evals\`, `…\Repo\DB\Mandates\`, `…\Repo\DB\Voice\`;
 * - `DDD\Domain\AI\Services\AgentSpaces\`, `…\Services\ClientTools\`, `…\Services\Connections\`, `…\Services\Conversations\`, `…\Services\Decisions\`, `…\Services\Documents\`,
 *   `…\Services\Drives\`, `…\Services\Eval\`, `…\Services\Installation\`, `…\Services\Mandates\`,
 *   `…\Services\Skills\`, `…\Services\SystemHealth\`, `…\Services\SystemPrompts\`, `…\Services\Texts\Embeddings\`, `…\Services\Voice\`,
 *   `…\Services\WebSearch\`;
 * - `DDD\Modules\Agents\`, `DDD\Presentation\Api\Admin\Connections\` and `DDD\Presentation\Api\Public\Connections\`
 *   (the connection endpoints), `DDD\Presentation\Api\Admin\Voice\` (the admin list of calls), `DDD\Presentation\Api\Agents\`,
 *   `DDD\Presentation\Mcp\` (the MCP / JSON-RPC wire DTOs, the tool executor, the console printer and the JSON-RPC
 *   base controller);
 * - `DDD\Symfony\Commands\Agents\` (the console commands of the module, their shared base, the option traits and the
 *   progress reporter).
 * - `DDD\Testing\Agents\` (the test hooks an application's suite extends, e.g. the surface snapshot test case; they
 *   need PHPUnit and are never services, {@see getExcludePatterns()}).
 *
 * Every `Services` namespace of the module is public ({@see getPublicServiceNamespaces()}). What is no service lives
 * outside them and stays private: the recorded-run clock and id sources (a static library, `Libs\RecordedRuns`), the
 * listener interfaces and the transaction listener (`Listeners\Conversations`), the worker state resetter
 * (`Messenger\Conversations`, reached through its tag), the console stream (`Presentation\Mcp\Agents`, built with
 * `new`), the trace log trait (`Traits\Conversations`) and the console commands (`Symfony\Commands\Agents`, reached
 * through the console tag their `#[AsCommand]` attribute gives them; an application command of the same name
 * replaces one, as for every command below `DDD\Symfony\Commands\`).
 *
 * One part works only with a library the package suggests and does not require
 * ({@see getNamespacesRequiringClasses()}): the session worker of a voice call, which holds a WebSocket to the voice
 * provider for the length of the call. Its classes sit in folders of their own
 * ({@see self::VOICE_SESSION_WORKER_NAMESPACES}); without the library they are no services and nothing loads them, and
 * the voice channel reads as unavailable
 * ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService::canVoiceRunHere()}).
 *
 * An application overrides a class by a same-path subclass under its own root namespace.
 */
class AgentsModule extends DDDModule
{
    /** @var string[] The namespaces this module owns (see the class docblock); each ends with a backslash. */
    public const array OWNED_NAMESPACES = [
        'DDD\\Domain\\AI\\Entities\\AgentSpaces\\',
        'DDD\\Domain\\AI\\Entities\\ClientTools\\',
        'DDD\\Domain\\AI\\Entities\\Connections\\',
        'DDD\\Domain\\AI\\Entities\\Conversations\\',
        'DDD\\Domain\\AI\\Entities\\Discovery\\',
        'DDD\\Domain\\AI\\Entities\\Documents\\',
        'DDD\\Domain\\AI\\Entities\\Drives\\',
        'DDD\\Domain\\AI\\Entities\\Environment\\',
        'DDD\\Domain\\AI\\Entities\\Evals\\',
        'DDD\\Domain\\AI\\Entities\\Installation\\',
        'DDD\\Domain\\AI\\Entities\\MCPPackages\\',
        'DDD\\Domain\\AI\\Entities\\Mandates\\',
        'DDD\\Domain\\AI\\Entities\\Models\\CustomerSelection\\',
        'DDD\\Domain\\AI\\Entities\\RecordedRuns\\',
        'DDD\\Domain\\AI\\Entities\\Skills\\',
        'DDD\\Domain\\AI\\Entities\\SystemHealth\\',
        'DDD\\Domain\\AI\\Entities\\Texts\\Embeddings\\',
        'DDD\\Domain\\AI\\Entities\\Voice\\',
        'DDD\\Domain\\AI\\Entities\\WebSearch\\',
        'DDD\\Domain\\AI\\Exceptions\\Connections\\',
        'DDD\\Domain\\AI\\Exceptions\\Conversations\\',
        'DDD\\Domain\\AI\\Exceptions\\Evals\\',
        'DDD\\Domain\\AI\\Exceptions\\Texts\\Embeddings\\',
        'DDD\\Domain\\AI\\Exceptions\\Voice\\',
        'DDD\\Domain\\AI\\Interfaces\\',
        'DDD\\Domain\\AI\\Libs\\Connections\\',
        'DDD\\Domain\\AI\\Libs\\RecordedRuns\\',
        'DDD\\Domain\\AI\\Listeners\\Connections\\',
        'DDD\\Domain\\AI\\Listeners\\Conversations\\',
        'DDD\\Domain\\AI\\MessageHandlers\\',
        'DDD\\Domain\\AI\\Messenger\\Conversations\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\Conversations\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\Guard\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\Mandates\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\WebSearch\\',
        'DDD\\Domain\\AI\\Repo\\DB\\AgentSpaces\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Connections\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Conversations\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Evals\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Mandates\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Voice\\',
        'DDD\\Domain\\AI\\Services\\AgentSpaces\\',
        'DDD\\Domain\\AI\\Services\\ClientTools\\',
        'DDD\\Domain\\AI\\Services\\Connections\\',
        'DDD\\Domain\\AI\\Services\\Conversations\\',
        'DDD\\Domain\\AI\\Services\\Decisions\\',
        'DDD\\Domain\\AI\\Services\\Documents\\',
        'DDD\\Domain\\AI\\Services\\Drives\\',
        'DDD\\Domain\\AI\\Services\\Eval\\',
        'DDD\\Domain\\AI\\Services\\Installation\\',
        'DDD\\Domain\\AI\\Services\\Mandates\\',
        'DDD\\Domain\\AI\\Services\\Skills\\',
        'DDD\\Domain\\AI\\Services\\SystemPrompts\\',
        'DDD\\Domain\\AI\\Services\\SystemHealth\\',
        'DDD\\Domain\\AI\\Services\\Texts\\Embeddings\\',
        'DDD\\Domain\\AI\\Services\\Voice\\',
        'DDD\\Domain\\AI\\Services\\WebSearch\\',
        'DDD\\Domain\\AI\\Traits\\Connections\\',
        'DDD\\Domain\\AI\\Traits\\Conversations\\',
        'DDD\\Modules\\Agents\\',
        'DDD\\Presentation\\Api\\Admin\\Connections\\',
        'DDD\\Presentation\\Api\\Admin\\Voice\\',
        'DDD\\Presentation\\Api\\Public\\Connections\\',
        'DDD\\Presentation\\Api\\Agents\\',
        'DDD\\Presentation\\Mcp\\',
        'DDD\\Symfony\\Commands\\Agents\\',
        'DDD\\Testing\\Agents\\',
    ];

    /**
     * @var string[] The namespaces of the voice session worker: the worker itself, and the message handler that
     * hands a call to it with its message. They need the WebSocket library ({@see self::VOICE_SESSION_WORKER_LIBRARY_CLASS});
     * each ends with a backslash.
     */
    public const array VOICE_SESSION_WORKER_NAMESPACES = [
        'DDD\\Domain\\AI\\MessageHandlers\\Voice\\SessionWorker\\',
        'DDD\\Domain\\AI\\Services\\Voice\\SessionWorker\\',
    ];

    /**
     * @var string A class of the WebSocket library the voice session worker opens its connection to the voice
     * provider with (`ratchet/pawl`, which runs on `react/event-loop`; both are suggested, not required)
     */
    public const string VOICE_SESSION_WORKER_LIBRARY_CLASS = Connector::class;

    /**
     * @var string The Slack Socket Mode runner (AgentConnections, local and dev inbound): it holds a WebSocket, so it
     * needs the same optional library as the voice session worker (`ratchet/pawl`, composer `suggest`)
     */
    public const string SOCKET_MODE_NAMESPACE = 'DDD\\Domain\\AI\\Services\\Connections\\Adapters\\Slack\\SocketMode\\';

    /** @var class-string The class the Socket Mode runner needs */
    public const string SOCKET_MODE_LIBRARY_CLASS = Connector::class;

    /** @var string The Google adapter of AgentConnections: it needs the optional `google/apiclient` (composer `suggest`) */
    public const string GOOGLE_ADAPTER_NAMESPACE = 'DDD\\Domain\\AI\\Services\\Connections\\Adapters\\Google\\';

    /** @var string The class of `google/apiclient` the Google adapter needs (a string: the library is optional) */
    public const string GOOGLE_LIBRARY_CLASS = 'Google\\Client';

    /** @return string[] {@see self::OWNED_NAMESPACES}: Core checks that no other package ships a class below them */
    public static function getOwnedNamespaces(): array
    {
        return static::OWNED_NAMESPACES;
    }

    public static function getSourcePath(): string
    {
        return __DIR__ . '/../..';
    }

    public static function getConfigPath(): ?string
    {
        return __DIR__ . '/../../../config/app';
    }

    /** Only namespaces the module owns ({@see self::OWNED_NAMESPACES}); every one of its `Services` namespaces. */
    public static function getPublicServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\AI\\Services\\AgentSpaces\\',
            'DDD\\Domain\\AI\\Services\\Conversations\\',
            'DDD\\Domain\\AI\\Services\\Decisions\\',
            'DDD\\Domain\\AI\\Services\\Skills\\',
            'DDD\\Domain\\AI\\Services\\SystemPrompts\\',
            // the mandates, the eval outbound guard and the embedding port (security gates and a port the core calls)
            'DDD\\Domain\\AI\\Services\\Mandates\\',
            'DDD\\Domain\\AI\\Services\\Eval\\',
            'DDD\\Domain\\AI\\Services\\Texts\\Embeddings\\',
            // the web research and its host hooks (the tools and the application fetch them with getService())
            'DDD\\Domain\\AI\\Services\\WebSearch\\',
            // the drive stores' registry (the drive tools and the file card fetch it)
            'DDD\\Domain\\AI\\Services\\Drives\\',
            // the document text extraction of the drive read tools
            'DDD\\Domain\\AI\\Services\\Documents\\',
            // the client tool request channel of Agent.UI (the tools, the chat routes and the purge command fetch it)
            'DDD\\Domain\\AI\\Services\\ClientTools\\',
            // the health report of System.Health and its sections port
            'DDD\\Domain\\AI\\Services\\SystemHealth\\',
            // the installation check (app:agent:check-installation) and its checks port
            'DDD\\Domain\\AI\\Services\\Installation\\',
            // the voice channel: call life, admission, start and ring, the call store port, the briefing, the provider
            // adapters and the session worker (the routes, the tools, the handlers and the reaper fetch them)
            'DDD\\Domain\\AI\\Services\\Voice\\',
            // AgentConnections: connections, adapters, the gate, approvals, inbound (the tools, routes, handlers and the
            // settled-turn listener fetch them)
            'DDD\\Domain\\AI\\Services\\Connections\\',
            'DDD\\Presentation\\Mcp\\Services\\',
        ];
    }

    /**
     * The voice session worker ({@see self::VOICE_SESSION_WORKER_NAMESPACES}) needs the WebSocket library
     * ({@see self::VOICE_SESSION_WORKER_LIBRARY_CLASS}). Without it the classes below these prefixes are no services
     * and are never loaded; every other voice class registers and loads as usual, and the voice channel reads as
     * unavailable.
     *
     * @return array<string, string> namespace prefix => the class that must exist
     */
    public static function getNamespacesRequiringClasses(): array
    {
        return array_fill_keys(static::VOICE_SESSION_WORKER_NAMESPACES, static::VOICE_SESSION_WORKER_LIBRARY_CLASS)
            + [static::SOCKET_MODE_NAMESPACE => static::SOCKET_MODE_LIBRARY_CLASS, static::GOOGLE_ADAPTER_NAMESPACE => static::GOOGLE_LIBRARY_CLASS];
    }

    /**
     * The service namespaces the container builds lazily (a proxy until first use) — every public service namespace
     * except the presentation services: the services the loop resolves through `getService()` and that pull large
     * graphs of further services.
     */
    public static function getLazyServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\AI\\Services\\AgentSpaces\\',
            'DDD\\Domain\\AI\\Services\\Conversations\\',
            'DDD\\Domain\\AI\\Services\\Decisions\\',
            'DDD\\Domain\\AI\\Services\\Skills\\',
            'DDD\\Domain\\AI\\Services\\SystemPrompts\\',
            'DDD\\Domain\\AI\\Services\\Mandates\\',
            'DDD\\Domain\\AI\\Services\\Eval\\',
            'DDD\\Domain\\AI\\Services\\Texts\\Embeddings\\',
            'DDD\\Domain\\AI\\Services\\WebSearch\\',
            'DDD\\Domain\\AI\\Services\\ClientTools\\',
            'DDD\\Domain\\AI\\Services\\SystemHealth\\',
            'DDD\\Domain\\AI\\Services\\Documents\\',
        ];
    }

    /**
     * The framework defaults plus the value objects of the presentation layer (request and response DTOs), the
     * module's own non-service plumbing (`Modules`) and the test hooks of `DDD\Testing\Agents\` (`Testing`: they
     * extend PHPUnit, which a production install does not have; as the framework's own `src/Testing`, they are loaded
     * by an application's test suite only), none of which the container may autowire.
     */
    public static function getExcludePatterns(): array
    {
        return [...parent::getExcludePatterns(), 'Dtos', 'Modules', 'Testing'];
    }
}
