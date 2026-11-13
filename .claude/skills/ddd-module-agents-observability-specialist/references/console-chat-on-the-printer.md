# Building the host's console chat on the printer

**Reader:** host developer and package maintainer

Contents: what ships and what the host writes · the printer contract · one streamed turn · the contract of the chat command · after the turn · transcripts and the TUI view model · replacing the printer · for the package maintainer · full class names

A console chat is the developer's fastest loop: type, watch every tool call and result stream past, read the payload
of a call. The package ships the rendering, not the command. The reference kernel's console runs only the
framework's commands (container lint, router, `app:entity:show-sql`); a chat command is the host's.

## What ships and what the host writes

| Ships in the package | The host writes |
|---|---|
| `AgentMessagePrinterInterface`, its implementation `AgentConsolePrinter` | the console command: options, input loop, auth, envelope |
| `AgentConsoleStream` (built by the loop from the output it is given) | where the conversation comes from (new or by id) |
| `ConsoleMarkdownRenderer` (assistant Markdown to ANSI or plain lines) | the per-turn summary line it prints |
| `ConversationHtmlTranscript` (a self-contained HTML file) | where the HTML file is written |
| `ConsoleViewModel`, `ConsoleViewModelBuilder`, `ConsoleViewBlock`, `ConsoleViewLine` (a terminal-neutral view model) | the full-screen shell, if any |

## The printer contract

`AgentMessagePrinterInterface` (fetched by that name; the id is a public alias of `AgentConsolePrinter`):

| Method | Does |
|---|---|
| `renderMessage($message, $output, $full, $debug, $collapseThreads, $conversation)` | prints one row: `[#<id>]` (or `[#i<index>]` for an unsaved row), a `D<id> │` gutter for a sub-agent's row, owner text, the assistant text through the Markdown renderer, a tool call as `→ name(args) [STATUS]` with the skills its row carries, the result on a `←` line |
| `renderTranscript($conversation, $full, $debug, $collapseThreads)` | the whole conversation per turn (`AIConversation::buildAgentTurns()`), each turn followed by `· turn N: tokens · cost · steps · delegations`, sub-agent turns inline after the call that spawned them; plain text, no colour |

Flags: `full` prints a tool result longer than 200 characters whole (otherwise one "N chars collapsed" line) and
keeps repeated status lines; `debug` prints, for a `load_skills` row, each loaded skill with an instruction preview,
and for every row with a request snapshot the reconstructed payload of that call — model and parameters, each wire
message once (a later identical long message reads "repeated"), the tool names once per change; `collapseThreads`
hides a sub-agent's own rows (the delegate call and its verdict stay). The assistant label comes from
`AgentApplicationNames::getConsoleAssistantLabel()`. Colour follows whether the output is decorated: piped output is plain.

## One streamed turn

`AIConversationAgentService::generateAgentReply()` streams every row it persists to the output it is given (through
an `AgentConsoleStream`); `$timings` adds a line per model call and tool with its seconds. A host's command drives one
owner turn in three steps (inside its command class; services from the container):

1. **Load the messages first.** `appendMessage()` reads `$conversation->messages`: a new conversation gets an empty
   `AIConversationMessages::newInstance()`, an existing one its compaction nodes and its bounded tail
   (`AIConversationMessagesService::findContextTailMessages()`) before the first append — never the lazy relation.
2. Append and persist the owner's message.
3. Drive the reply inside a turn envelope.

```php
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Output\OutputInterface;

protected function driveStreamedOwnerTurn(AIConversation $conversation, string $ownerText, OutputInterface $output): void
{
    /** @var AIConversationMessagesService $aIConversationMessagesService */
    $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
    /** @var AIConversationAgentService $aIConversationAgentService */
    $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
    /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
    $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);

    $ownerMessage = $conversation->appendMessage(
        AIConversationMessage::newInstance(),
        AIConversationMessage::MESSAGE_TEXT,
        $ownerText,
        role: AIConversationMessage::ROLE_USER,
        authorType: AIConversationMessage::AUTHOR_USER
    );
    $aIConversationMessagesService->persistInboundRowIndexSafe($conversation, $ownerMessage);

    $turnEnvelope = $aIUsageEnvelopesService->openEnvelope(
        $conversation->accountId ?? null,
        AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN,
        AIUsageInitiator::CLI
    );
    try {
        $aIConversationAgentService->generateAgentReply($conversation, $output, timings: true);
    } finally {
        $aIUsageEnvelopesService->closeEnvelope($turnEnvelope);
    }
}
```

## The contract of the chat command

| Point | Contract |
|---|---|
| Auth | `AuthService::instance()->setAccount($account)` before the first read: the rights confine reads to it, a dispatched message captures it |
| Conversation | an existing one by id: load it, its compaction nodes and its bounded tail (`AIConversationMessagesService::findContextTailMessages()`), and `AgentSpacesService::attachAgentSpaceRelations()`; a new one: how a conversation is started belongs to `ddd-module-agents-loop-specialist` (or drive it once through `driveConversation()` and continue by id) |
| Run state | only an IDLE conversation is driven synchronously; `generateAgentReply()` takes no run claim |
| Envelope | one per turn, as above; without it the turn's model calls are not grouped under an envelope and a budget lift granted on the envelope does nothing (an exception only where the host's own model-call gate requires an envelope); the workers' envelope middleware: `ddd-module-agents-specialist` |
| Budget | a debug process may lift the budget for itself: `AIUsageEnvelopesService::grantBudgetBypassForTheProcess()` with `AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONSOLE_OPTION)`, behind an explicit option; spend is still booked |
| Side work | the console has no consumer: compaction, the recall embedding and the display summary run inline (`generateAgentReply()` defaults). `compactionEnabled: false` / `sideWorkEnabled: false` measure the turn alone |
| Input | repair the encoding and strip control characters from terminal input before appending; one invalid byte breaks the JSON of the request |
| Worker path | `resumeConversationIfReady($conversation, async: true)` dispatches the turn instead; it runs silently (no output stream in the worker) — print the transcript afterwards |
| Without it | a host can only watch its agent through the database rows and its frontend |

## After the turn

- The turn's spend: `$conversation->messages->getTotalTokens()` and `getTotalCostUsd()` before and after the turn
  (the reply's overhead — decision calls, activity lines — reaches the conversation's `overheadCostsInUSD` only when the
  host records booked costs; with ddd-ai's defaults it stays 0, see [metrics-and-cost](metrics-and-cost.md)).
- What the model saw: run the same command with `debug`, or read the request snapshots
  ([debugging-a-turn](debugging-a-turn.md)).

## Transcripts and the TUI view model

- `(new ConversationHtmlTranscript())->render($conversation)` returns one HTML document: collapsible tool results, the
  assistant Markdown rendered in the browser (marked.js from a CDN), sub-agents nested under their call, everything
  escaped. The caller writes the file — after every turn, if it wants a live export. It is a plain class, not a
  service.
- `(new ConsoleViewModelBuilder())->fromConversation($conversation, $expandToolResults)` builds a `ConsoleViewModel`: one
  `ConsoleViewBlock` per row (tool calls collapsible), a metrics line per turn, plain lines without ANSI. The model
  answers what a full-screen shell needs — `visibleLines()`, `blockAtRow()`, `toggleAtRow()` for a click,
  `viewportLines()` and `maxScrollOffset()` for scrolling. The terminal library and the event loop are the host's.

## Replacing the printer

Alias `AgentMessagePrinterInterface` to the host's own implementation in its service configuration; a second
implementation without the alias leaves the package's printer in place. The loop resolves the printer through the
container for every console-driven reply.

## For the package maintainer

- The printer holds per-process state (seen debug messages, the last tool list, the status-line allowance per
  conversation); a change keeps it keyed per conversation, because a sub-agent's rows render in the middle of the
  parent's turn.
- `ConsoleViewModelBuilder` and `ConversationHtmlTranscript` read the conversation only, no repository and no
  terminal; keep them pure so they stay unit-testable.

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant` · `DDD\Domain\AI\Entities\Conversations\AIConversation` ·
`DDD\Domain\AI\Services\Conversations\AIConversationAgentService` ·
`DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages` ·
`DDD\Domain\AI\Services\Conversations\AIConversationMessagesService` ·
`DDD\Domain\AI\Services\AIUsageEnvelopesService` · `DDD\Domain\AI\Entities\Environment\AgentApplicationNames` ·
`DDD\Presentation\Mcp\Agent\AgentConsolePrinter` · `DDD\Presentation\Mcp\Agent\AgentConsoleStream` ·
`DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface` ·
`DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService` · `DDD\Infrastructure\Services\AuthService` ·
`DDD\Presentation\Mcp\Agent\ConsoleMarkdownRenderer` · `DDD\Presentation\Mcp\Agent\Tui\ConsoleViewBlock` ·
`DDD\Presentation\Mcp\Agent\Tui\ConsoleViewLine` · `DDD\Presentation\Mcp\Agent\Tui\ConsoleViewModel` ·
`DDD\Presentation\Mcp\Agent\Tui\ConsoleViewModelBuilder` · `DDD\Presentation\Mcp\Agent\ConversationHtmlTranscript`
