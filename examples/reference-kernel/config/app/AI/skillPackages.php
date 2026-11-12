<?php

declare(strict_types=1);

use AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage;

/**
 * The reference application's skill → MCP-package binding. It REPLACES the package's file whole (application config
 * roots are searched first), so it lists only the skill this host offers. By class name: the package's id lookup
 * (`MCPPackageIdsService`) scans the application root under the root namespace `App\` only, and this host's root
 * namespace is `AgentsReference\`.
 */
return [
    'Demo.Notes' => [DemoNotesMCPPackage::class],
];
