<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Mime;

use Parsedown;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The markdown of an outgoing mail body (model text): a markdown image (`![alt](url)`) becomes its alt text, never an
 * `<img>` — a remote image in a mail the agent sends is a tracking pixel an injected instruction can plant (it reports
 * when and where the recipient opened the mail). Raw HTML stays escaped by safe mode.
 */
#[Exclude]
class AgentConnectionMailMarkdown extends Parsedown
{
    public function __construct()
    {
        $this->setSafeMode(true);
        $this->setBreaksEnabled(true);
    }

    /** @param array<string, mixed> $Excerpt */
    protected function inlineImage($Excerpt)
    {
        $image = parent::inlineImage($Excerpt);
        if (!is_array($image)) {
            return $image;
        }
        $altText = (string)($image['element']['attributes']['alt'] ?? '');
        return ['extent' => $image['extent'], 'element' => ['name' => 'span', 'text' => $altText]];
    }
}
