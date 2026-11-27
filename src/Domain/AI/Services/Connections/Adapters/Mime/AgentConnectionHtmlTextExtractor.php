<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Mime;

use DDD\Infrastructure\Untrusted\SafeXml;
use DOMElement;
use DOMNode;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The visible text of an untrusted HTML body (a mail, an exported document): parsed with Core's safe HTML loader, with
 * every node a reader does not see dropped before the text is read: script, style, head, template, noscript, iframe,
 * object, svg, and elements hidden by the `hidden` attribute, `aria-hidden="true"`, or an inline style with
 * `display:none`, `visibility:hidden`, `opacity:0`, `font-size:0` or `max-height:0`. Hidden text is a common
 * carrier of instructions aimed at a model. Block elements end a line. Built here first; Core may lift it into
 * DDD\Infrastructure\Untrusted later.
 */
#[Exclude]
class AgentConnectionHtmlTextExtractor
{
    public const int MAXIMUM_HTML_BYTES = 2_000_000;

    public const array DROPPED_ELEMENTS = ['script', 'style', 'head', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'canvas', 'title', 'meta', 'link'];

    public const array BLOCK_ELEMENTS = ['p', 'div', 'br', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'table', 'ul', 'ol', 'section', 'article', 'header', 'footer'];

    public static function extractVisibleText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        if (strlen($html) > static::MAXIMUM_HTML_BYTES) {
            $html = substr($html, 0, static::MAXIMUM_HTML_BYTES);
        }
        $domDocument = SafeXml::loadHtml($html, static::MAXIMUM_HTML_BYTES + 64);
        $text = static::collectText($domDocument, 0);
        $text = (string)preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = (string)preg_replace("/ *\n */", "\n", $text);
        return trim((string)preg_replace("/\n{3,}/", "\n\n", $text));
    }

    protected static function collectText(DOMNode $node, int $depth): string
    {
        if ($depth > 200) {
            return '';
        }
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string)$node->nodeValue;
        }
        if ($node instanceof DOMElement && static::isInvisible($node)) {
            return '';
        }
        $text = '';
        foreach ($node->childNodes as $childNode) {
            $text .= static::collectText($childNode, $depth + 1);
        }
        if ($node instanceof DOMElement && in_array(strtolower($node->tagName), static::BLOCK_ELEMENTS, true)) {
            $text .= "\n";
        }
        return $text;
    }

    public static function isInvisible(DOMElement $domElement): bool
    {
        if (in_array(strtolower($domElement->tagName), static::DROPPED_ELEMENTS, true)) {
            return true;
        }
        if ($domElement->hasAttribute('hidden') || strtolower($domElement->getAttribute('aria-hidden')) === 'true') {
            return true;
        }
        $style = strtolower((string)preg_replace('/\s+/', '', $domElement->getAttribute('style')));
        if ($style === '') {
            return false;
        }
        return preg_match('/(^|;)(display:none|visibility:hidden|opacity:0(\.0+)?(;|$|!)|font-size:0(px|pt|em|rem|%)?(;|$|!)|max-height:0(px)?(;|$|!))/', $style) === 1;
    }
}
