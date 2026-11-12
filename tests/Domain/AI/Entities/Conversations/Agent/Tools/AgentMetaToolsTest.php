<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The nine meta tools implement AgentMetaTool, answer their own name, and the effect catalog
 * reads their declared effects.
 */
class AgentMetaToolsTest extends UnitTestCase
{
    public function testEachMetaToolAnswersItsName(): void
    {
        foreach (AgentMetaTools::TOOL_CLASSES as $metaToolClass) {
            $metaTool = new $metaToolClass();
            self::assertInstanceOf(AgentMetaTool::class, $metaTool);
            self::assertSame($metaToolClass::NAME, $metaTool->getToolName());
            if (method_exists($metaToolClass, 'create')) {
                // load_skills, load_tool and delegate build their schema from a catalog; the rest from nothing
                self::assertSame($metaToolClass::NAME, $metaToolClass::create()->toOpenAiToolSchema()['function']['name'] ?? null);
            }
        }
    }

    public function testTheListCoversTheConversationsMetaToolNames(): void
    {
        $names = array_map(static fn (string $metaToolClass): string => $metaToolClass::NAME, AgentMetaTools::TOOL_CLASSES);
        $expected = AIConversationMessage::META_TOOL_NAMES;
        sort($names);
        sort($expected);
        self::assertSame($expected, $names);
    }

    public function testTheCatalogReadsTheDeclaredEffects(): void
    {
        foreach (AgentMetaTools::TOOL_CLASSES as $metaToolClass) {
            self::assertSame($metaToolClass::DECLARED_EFFECT, MCPToolEffectCatalog::getEffectForToolName($metaToolClass::NAME)?->effect);
        }
    }
}
