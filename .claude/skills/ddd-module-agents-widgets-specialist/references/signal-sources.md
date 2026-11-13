# Signal sources

**Reader:** host developer and package maintainer

Contents: what a signal row is · the source is an enum case · the questions every source answers · the registry · adding a host source · the core sources a widget meets · traps

## What a signal row is

A message the model reads and the owner does not see in the chat, written by neither of them: a widget click, a notice
that the platform wrote a widget, the client's render report, a work order, the loop's nudge. It is stored as
`AIConversationMessage::MESSAGE_SIGNAL` with role system and the provenance in `AIConversationMessage::$signalSource`.
Only when the model's wire is built does it become a user entry `[SIGNAL:<source>] <text>`; a mid-conversation system
message is not reliable across providers, and a stored user role would let every reader mistake it for the owner.
The chat is expected to hide signal rows (a duty of the web half). This reference covers signals as far as a widget or a source declares them; the
loop's own signals are `ddd-module-agents-loop-specialist`.

## The source is an enum case

`AIConversationMessage::$signalSource` is a string (it also carries markers on text rows and a tool name on tool rows),
read through `ConversationSignalSources::tryFrom()`, which returns the case of a `DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource` enum or null.
The package declares its own in `AIConversationSignalSource` (with `SIGNAL_SOURCE_*` constants on
`AIConversationMessage`) and the phone channel's in `PhoneConversationSignalSource`. A host declares its own sources in
its own enum; its writer passes the typed case's value.

## The questions every source answers

Basic questions — the enum overrides the ones that differ from the default of `ConversationSignalSourceDefaultAnswers`
(a platform source that owes work and nothing else):

| Question | Default | Effect |
|---|---|---|
| `getSpeaker()` | `PLATFORM` | who speaks: `ConversationSignalSpeaker` OWNER, AGENT, SUPERVISOR, LOOP, PLATFORM |
| `startsATurn()` | false | the signal starts a turn of its own (a work order, a form submit) |
| `isPickedUpByRunningTurn()` | false | a running turn reads it at its next model call instead of a second resume |
| `owesWork()` | true | false = a pure marker the owed-turn walks see through; injecting it never resumes |
| `invalidatesEarlierReads()` | false | earlier reads may no longer hold (the read dedup treats it like a write) |
| `isPostedByTheClient()` | false | the client may post it through the inject-signal endpoint — the security gate, always explicit |
| `isConsumedByToolCall()` | false | a later call of that tool in the same turn consumes it |
| `carriesAnOwnerStatement()` | false | the owner's action counts as their statement |
| `marksOwnerPresence()` | false | the owner is there (metrics) |
| `getMetricsEntryPoint()`, `getAnalysisTranscriptLabel()` | null | stored and model-facing presentation |

Derived questions (`isOwnerAuthored()`, `isOwnerFrontendAction()`, `startsAnOwnerTurn()`, `isWorkOrder()`, …) are
computed by the trait from the basic ones; no enum answers them itself. The "core vocabulary" questions name cases of
the package's enum only.

| Kind of source | Speaker | Typical answers |
|---|---|---|
| an owner's click on a host widget | OWNER | `isPostedByTheClient`, `isPickedUpByRunningTurn`, `invalidatesEarlierReads`, `carriesAnOwnerStatement` |
| a form widget's own submit row | OWNER | `startsATurn`, `isPostedByTheClient`, `invalidatesEarlierReads`, `carriesAnOwnerStatement` |
| a platform notice of a host domain | PLATFORM | the defaults; `owesWork` false when it is context only |

`isOwnerAuthored()` (speaker OWNER and invalidates earlier reads) decides whether a row is a widget reaction at all
(`AIConversationMessage::isOwnerAuthoredInbound()`); the database preselection of the widget view reads the value list
`ConversationSignalSources::getValuesThatAreOwnerAuthored()`.

## The registry

`ConversationSignalSources` builds once per process: the package enum first, then every `*ConversationSignalSource.php`
DIRECTLY in the package's signal source folder and in `Domain/<YourDomain>/Entities/AI/Conversations/SignalSources/`
of every source root — a flat glob, no subfolders (widgets, nodes, data sources and resolvers are found at any depth).
Like every folder registry of this skill it sees host classes only under the root namespace `App`; another root
namespace: `ddd-module-agents-specialist` (override points). The SQL readers take named value lists (`getValuesThatCarryAnOwnerStatement()`,
`getValuesThatStartATurn()`, `getValuesPickedUpByRunningTurn()`, …). Build errors fail soft in production (logged and
skipped) and throw under `APP_ENV=test` or `ConversationSignalSources::$throwErrors`: an enum that does not load, a
class that is no string-backed enum implementing the interface, an enum without the trait, a short name that is not
`<Prefix>ConversationSignalSource`, a stored value another enum already declares. Enums are never overridden.

## Adding a host source

```php
namespace App\Domain\Notes\Entities\AI\Conversations\SignalSources;

use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSourceDefaultAnswers;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSpeaker;

enum NotesConversationSignalSource: string implements ConversationSignalSource
{
    use ConversationSignalSourceDefaultAnswers;

    /** The owner submitted the note form; the submit starts the owner's turn. */
    case NOTE_FORM_SUBMIT = 'NOTE_FORM_SUBMIT';

    public function getSpeaker(): ConversationSignalSpeaker
    {
        return ConversationSignalSpeaker::OWNER;
    }

    public function startsATurn(): bool
    {
        return true;
    }

    public function isPostedByTheClient(): bool
    {
        return true;
    }

    public function invalidatesEarlierReads(): bool
    {
        return true;
    }

    public function carriesAnOwnerStatement(): bool
    {
        return true;
    }
}
```

The stored value is persisted forever: never rename a case's value. A value already used by another enum fails the
build.

## The core sources a widget meets

| Source | Who writes it | Meaning |
|---|---|---|
| `FRONTEND_WIDGET_CLICK` | the client | the owner acted on a widget; picked up by a running turn |
| `FRONTEND_CHANNEL_CONNECTION`, `OWNER_ACTION_CONFIRM` | the client | an account connected, an action confirmed; picked up by a running turn |
| `FRONTEND_NAVIGATION` | the client | a flow outside the chat opened or left; owes work and is not picked up, so each post resumes an idle conversation |
| `FRONTEND_WIDGET_RENDER_REPORT` | the client | what did not render; owes no work |
| `FRONTEND_WIDGET_INVALID` | the client | a block the client cannot render; owes work, each post resumes the conversation |
| `DASHBOARD_WIDGET_CONTEXT` | the client | the context a page hands to the conversation it starts |
| `PLATFORM_WIDGET_EMITTED` | the platform | a widget the platform wrote on its own; owes no work |
| `OWNER_WIDGET_EDIT_APPLIED` | the platform | an owner's widget edit applied at turn open; stored rows may carry the older value of `LEGACY_OWNER_CARD_EDIT_APPLIED`, and readers accept both |
| `OWNER_QUESTION_PICK` | the loop | the owner picked an answer of an owner question |
| `WIDGET_TREE_FINDINGS` | the loop | a `ComposedView` lost parts of its tree; asks for the corrected block |

## Traps

- `isPostedByTheClient()` is the only gate between a browser and the turn semantics of a source. Never answer true for
  a source that starts work the owner did not do.
- A source that owes work and is not picked up by a running turn resumes the conversation on every post: a client that
  posts on every keystroke starts a turn per keystroke.
- The `[SIGNAL:…]` prefix is a convention for the model, not an authenticated channel. Keep facts that carry authority
  out of a client-posted text.
