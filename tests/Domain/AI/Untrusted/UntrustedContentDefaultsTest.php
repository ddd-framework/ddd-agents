<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Untrusted;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolContentTrust;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshot;
use DDD\Presentation\Mcp\Agent\ConversationHtmlTranscript;
use DDD\Presentation\Mcp\Client\AI\AgentMandatesMCPPackage;
use DDD\Presentation\Mcp\Client\AI\AgentMemoriesMCPPackage;
use DDD\Presentation\Mcp\Client\AI\WebSearchMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeUntrustedReadGateMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The untrusted-content defaults: a READ or WORKSPACE tool is untrusted unless declared trusted, a write is trusted,
 * an unmarked untrusted result carries the reading rule, a screenshot decodes only a PNG, the transcript runs no script.
 */
class UntrustedContentDefaultsTest extends UnitTestCase
{
    public function testReadsAreUntrustedUnlessDeclaredTrusted(): void
    {
        $probePackage = new ReflectionClass(ProbeUntrustedReadGateMCPPackage::class);
        self::assertTrue(MCPToolContentTrust::isUntrustedToolMethod($probePackage, $probePackage->getMethod('probeReadForeignText')));
        self::assertTrue(MCPToolContentTrust::isUntrustedToolMethod($probePackage, $probePackage->getMethod('probeRunWorkspaceCommand')));
        self::assertFalse(MCPToolContentTrust::isUntrustedToolMethod($probePackage, $probePackage->getMethod('probeReadPlatformRecord')));
        self::assertFalse(MCPToolContentTrust::isUntrustedToolMethod($probePackage, $probePackage->getMethod('probeWriteRecord')));

        $webSearchPackage = new ReflectionClass(WebSearchMCPPackage::class);
        self::assertTrue(MCPToolContentTrust::isUntrustedToolMethod($webSearchPackage, $webSearchPackage->getMethod('getWebPageAsMarkdown')));
        $memoriesPackage = new ReflectionClass(AgentMemoriesMCPPackage::class);
        foreach ($memoriesPackage->getMethods() as $toolMethod) {
            if ($toolMethod->getAttributes(\DDD\Domain\AI\Entities\MCPPackages\MCPTool::class) !== []) {
                self::assertFalse(MCPToolContentTrust::isUntrustedToolMethod($memoriesPackage, $toolMethod), $toolMethod->getName());
            }
        }
        // a subclass keeps the trust its parent package declares
        $mandatesPackage = new ReflectionClass(AgentMandatesMCPPackage::class);
        foreach ($mandatesPackage->getMethods() as $toolMethod) {
            if ($toolMethod->getAttributes(\DDD\Domain\AI\Entities\MCPPackages\MCPTool::class) !== []) {
                self::assertFalse(MCPToolContentTrust::isUntrustedToolMethod($mandatesPackage, $toolMethod), $toolMethod->getName());
            }
        }
    }

    public function testTheWriteGateIsOffAndTheUnseenUrlRefusalOnByDefault(): void
    {
        self::assertSame(MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF, MCPToolContentTrust::getWriteAfterUntrustedReadMode());
        self::assertSame(MCPToolContentTrust::UNSEEN_URL_AFTER_UNTRUSTED_READ_REFUSE, MCPToolContentTrust::getUnseenUrlAfterUntrustedReadMode());
    }

    public function testAnUnmarkedUntrustedResultCarriesTheReadingRule(): void
    {
        self::assertSame(ToolResultUntrustedSegments::UNMARKED_READING_RULE, ToolResultUntrustedSegments::buildPlatformNoteWithReadingRule(null, 'page text', true));
        self::assertSame("note\n" . ToolResultUntrustedSegments::UNMARKED_READING_RULE, ToolResultUntrustedSegments::buildPlatformNoteWithReadingRule('note', 'page text', true));
        self::assertNull(ToolResultUntrustedSegments::buildPlatformNoteWithReadingRule(null, 'record', false));
        $markedText = ToolResultUntrustedSegments::wrap('https://example.org', 'text');
        self::assertSame(ToolResultUntrustedSegments::READING_RULE, ToolResultUntrustedSegments::buildPlatformNoteWithReadingRule(null, $markedText, true));
    }

    public function testAScreenshotDecodesOnlyAPng(): void
    {
        $pngScreenshot = new PageScreenshot();
        $gdImage = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($gdImage);
        $pngScreenshot->content = base64_encode((string)ob_get_clean());
        self::assertSame(4, $pngScreenshot->getPixelWidth());
        self::assertSame(3, $pngScreenshot->getPixelHeight());
        self::assertCount(1, $pngScreenshot->buildSegmentWindowBase64(100, 0, 1));

        $postScriptScreenshot = new PageScreenshot();
        $postScriptScreenshot->content = base64_encode("%!PS-Adobe-3.0\nshowpage\n");
        self::assertSame(0, $postScriptScreenshot->getPixelWidth());
        self::assertSame([], $postScriptScreenshot->buildSegmentWindowBase64(100, 0, 1));
    }

    public function testTheTranscriptRunsNoScript(): void
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = '<img src=x onerror=alert(1)> **bold**';
        $message->indexInConversation = 0;
        $messages = new AIConversationMessages();
        $messages->add($message);
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $messages;

        $html = (new ConversationHtmlTranscript())->render($conversation);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString("Content-Security-Policy", $html);
    }
}
