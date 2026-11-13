<?php
// placeholder {{NAMESPACE}} sample: AgentsReference\Presentation\Mcp\Demo
// placeholder {{FEATURE}} sample: DemoNotes
// placeholder {{BUNDLE_ID}} sample: demo

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;

/**
 * The HTTP path only: a bundle is the tool list of one MCP mount. tools/list and tools/call both read
 * INCLUDED_PACKAGES, so the listed and the callable tools cannot differ. The agent loop never reads a bundle.
 */
class {{FEATURE}}MCPBundle extends MCPBundle
{
    /** @var string[] The MCPPackage classes of this mount */
    public const array INCLUDED_PACKAGES = [
        {{FEATURE}}MCPPackage::class,
    ];

    /** @var string|null The bundle id */
    public static ?string $name = '{{BUNDLE_ID}}';

    /** @var string|null What the mount offers */
    public static ?string $description = 'The owner\'s items.';
}
