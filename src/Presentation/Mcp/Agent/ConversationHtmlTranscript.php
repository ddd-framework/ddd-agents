<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;

/**
 * Renders an agent conversation to a self-contained HTML transcript file — the pragmatic stand-in for a terminal
 * TUI (the console is line-oriented and cannot do collapse/scroll/mouse). The browser gives, for free, exactly the
 * three things the terminal can't: native collapsible tool results (`<details>`), real Markdown (bold, tables,
 * lists via marked.js from a CDN) and mouse/scroll/search.
 *
 * Pure trace → HTML string; no I/O (the caller writes the file). Tool args/results are HTML-escaped; assistant
 * Markdown is carried as the element's textContent and rendered client-side, so model `<…>`/`&` cannot inject.
 * Delegation children render as nested, indented blocks under their delegate call.
 */
class ConversationHtmlTranscript
{
    public function render(AIConversation $conversation): string
    {
        $messages = $conversation->messages?->getElements() ?? [];
        $body = '';
        foreach ($messages as $message) {
            $body .= $this->renderMessage($message, $conversation);
        }

        $totalTokens = $conversation->messages?->getTotalTokens() ?? 0;
        $totalCost = $conversation->messages?->getTotalCostUsd() ?? 0.0;
        $skills = implode(', ', $conversation->messages?->getLoadedSkillNames() ?? []);
        $conversationId = $conversation->id !== null ? (string)$conversation->id : 'ephemeral';

        $header = sprintf(
            '<header><h1>Agent transcript</h1><div class="meta">conversation %s · %d tokens · $%.4f · skills [%s]</div></header>',
            $this->escape($conversationId),
            $totalTokens,
            $totalCost,
            $this->escape($skills)
        );

        return $this->shell($header . $body);
    }

    protected function renderMessage(AIConversationMessage $message, AIConversation $conversation): string
    {
        $idToken = $message->id !== null ? '#' . $message->id : '#i' . ($message->indexInConversation ?? 0);
        $isDelegation = $conversation->isDelegation();
        $delegationClass = $isDelegation ? ' delegation' : '';

        return match ($message->messageType) {
            AIConversationMessage::MESSAGE_TEXT => $this->renderText($message, $idToken, $isDelegation, $delegationClass),
            AIConversationMessage::MESSAGE_TOOL_CALL => $this->renderToolCall($message, $idToken, $delegationClass),
            AIConversationMessage::MESSAGE_ERROR => sprintf(
                '<div class="msg error%s"><span class="id">%s</span><span class="err">✗ %s</span></div>',
                $delegationClass,
                $this->escape($idToken),
                $this->escape((string)$message->message)
            ),
            default => '',
        };
    }

    protected function renderText(AIConversationMessage $message, string $idToken, bool $isDelegation, string $delegationClass): string
    {
        $isUser = $message->role === AIConversationMessage::ROLE_USER;
        if ($isUser) {
            $label = $isDelegation ? 'task' : 'you';
            return sprintf(
                '<div class="msg user%s"><span class="id">%s</span><span class="role">%s&gt;</span> <span class="utext">%s</span></div>',
                $delegationClass,
                $this->escape($idToken),
                $this->escape($label),
                $this->escape((string)$message->message)
            );
        }
        // Assistant: carry raw Markdown as textContent (escaped here, decoded back by the browser, rendered by marked).
        return sprintf(
            '<div class="msg assistant%s"><span class="id">%s</span><span class="role">agent&gt;</span><div class="md">%s</div></div>',
            $delegationClass,
            $this->escape($idToken),
            $this->escape((string)$message->message)
        );
    }

    protected function renderToolCall(AIConversationMessage $message, string $idToken, string $delegationClass): string
    {
        $name = $message->toolCall->name ?? '?';
        $args = $message->toolCall->argumentsAsJson();
        $status = $message->toolCall->status ?? '';
        $result = $message->toolCall->result ?? '';
        $resultLen = mb_strlen($result);
        $skills = '';
        $messageLoadedSkillNames = $message->getLoadedSkillNames();
        if ($messageLoadedSkillNames !== []) {
            $skills = ' · skills [' . implode(', ', $messageLoadedSkillNames) . ']';
        }

        // The result is collapsed by default (<details> closed) — the whole point of the HTML view: heavy tool
        // dumps stay out of the way, one click expands them.
        $resultBlock = $result !== ''
            ? sprintf(
                '<details class="result"><summary>← result · %d chars</summary><pre>%s</pre></details>',
                $resultLen,
                $this->escape($result)
            )
            : '';

        return sprintf(
            '<div class="msg tool%s"><span class="id">%s</span><span class="call">→ <b>%s</b>(%s) <span class="status">[%s]</span><span class="skills">%s</span></span>%s</div>',
            $delegationClass,
            $this->escape($idToken),
            $this->escape((string)$name),
            $this->escape($args),
            $this->escape((string)$status),
            $this->escape($skills),
            $resultBlock
        );
    }

    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function shell(string $body): string
    {
        // marked.js (CDN) renders the assistant Markdown client-side; <details> handle collapse natively.
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Agent transcript</title>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
  :root { color-scheme: light dark; }
  body { font: 14px/1.55 ui-monospace, SFMono-Regular, Menlo, monospace; max-width: 64rem; margin: 0 auto; padding: 1.5rem; }
  header h1 { font-size: 1.1rem; margin: 0 0 .25rem; }
  header .meta { color: #888; font-size: .85rem; margin-bottom: 1rem; }
  .msg { padding: .35rem 0; border-top: 1px solid #8881; }
  .id { color: #999; font-size: .75rem; margin-right: .5rem; }
  .role { font-weight: 700; }
  .user .role { color: #2a7; }
  .assistant .role { color: #c83; }
  .tool .call { color: #a70; }
  .status { color: #999; }
  .skills { color: #38a; }
  .err { color: #c33; }
  .delegation { margin-left: 1.5rem; border-left: 2px solid #b6b; padding-left: .75rem; }
  .md { display: inline-block; vertical-align: top; }
  .md table { border-collapse: collapse; margin: .5rem 0; }
  .md th, .md td { border: 1px solid #8884; padding: .2rem .5rem; }
  .md code { background: #8882; padding: 0 .25rem; border-radius: 3px; }
  details.result { margin: .25rem 0 .25rem 2rem; }
  details.result summary { cursor: pointer; color: #888; }
  details.result pre { white-space: pre-wrap; word-break: break-word; background: #8881; padding: .5rem; border-radius: 4px; max-height: 28rem; overflow: auto; }
</style>
</head>
<body>
$body
<script>
  document.querySelectorAll('.md').forEach(function (el) {
    el.innerHTML = marked.parse(el.textContent);
  });
</script>
</body>
</html>
HTML;
    }
}
