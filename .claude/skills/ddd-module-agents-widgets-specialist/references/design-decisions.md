# Design decisions

**Reader:** host developer and package maintainer

Contents: a widget is a block in the message text · one class per wire name, found by folder · wire names are append-only · the code writes what it knows, the model copies only where no row can be written · the origin travels in the block · the widget class reads its own answer · signals are stored apart from owner text · a signal source is an enum that answers questions · one composable wire type · the reader drops and reports, it never refuses · tool numbers are bound and snapshotted, references are minted · the client reports what did not render · the realtime signal is a ping · activity lines only while someone watches

## A widget is a block in the message text

**Rule.** A widget travels as a fenced JSON block inside the assistant's text (or a tool row's text), flat fields next
to `type`, one written form.

**Reason.** Prose and widgets alternate in one answer; the stored text is the replay, the history and the model's own
memory of what it showed; one parser per side reads it. A flat object survives the frontend's rewrite unchanged.

**Rejected.** Widgets as native tool calls (they cannot sit between two paragraphs, and a turn of only tool calls has
no answer); a second written notation for the composable kit (compared with the fence, it produced fewer valid trees); a nested `payload` object (it arrives doubly wrapped).

**Would change if.** Model providers offered a structured output that interleaves typed blocks with text and replays
reliably across providers.

## One class per wire name, found by folder

**Rule.** Every wire name has exactly one `ConversationWidget` subclass named after it in a widget folder;
`ConversationWidgetClasses` finds it by glob. The core imports no domain widget and holds no list of names.

**Reason.** A list in the core grows with every application and couples the core to its domains; a class per type
gives the backend one place to read constants (awaits the owner, the summary, the email flag) and to write and read
blocks. Folder registration lets parallel work add widgets as new files only.

**Rejected.** A tagged-service registry (not how the framework resolves classes, and a widget is a value object, not a
service); the framework's class finder over the whole tree (slow, cached so a new class appears late); name lists in
the loop's gates.

**Would change if.** The number of widget folders made the per-process glob measurable in the turn's latency.

## Wire names are append-only

**Rule.** A wire name never changes and never ends in `Directive`; the legacy long form resolves by stripping the suffix
once. Stored rows are never rewritten.

**Reason.** Every stored conversation is replayed by the current code; a renamed name renders nothing in all of them.
Stripping the suffix once on both sides keeps old rows alive without a migration of message text.

**Rejected.** Rewriting stored rows on rename (risky on the largest table, and it changes what the model once wrote);
aliases per widget (a second list that drifts from the frontend).

**Would change if.** Conversations got a retention short enough that no stored row outlives a rename.

## The code writes what it knows, the model copies only where no row can be written

**Rule.** A fact a tool or the platform knows for certain is written as its own row through the one writer, which
decides the delivery; a dictated block is returned only when no row can be written (no chat, a sub-agent, the phone
line).

**Reason.** A model asked to copy a block skips it, reshapes it or claims a widget that is not there; a written row is
always there. One writer means one place decides delivery for every caller.

**Rejected.** Dictating every widget (models copy blocks unreliably); each tool deciding its own delivery (a sub-agent's
widget appeared nowhere, a phone call got a widget nobody could see); writing into the root from a sub-agent (nobody
asked for it there).

**Would change if.** Models copied dictated blocks byte for byte in every surface.

## The origin travels in the block

**Rule.** A block a tool or the platform stored carries `emittedBy` as its last field; a block without it was written
by the model. The model never sees the field.

**Reason.** The origin of a block is a property of the block, not of the row label, and readers (the inspector, the
answer readers) need it per block. Appending it last keeps the bytes before it equal to the dictated form.

**Rejected.** Reading the origin from the row's `signalSource` (a label that also carries other values); a column per
origin.

**Would change if.** A block ever needed more provenance than its writer and reason.

## The widget class reads its own answer

**Rule.** What the owner did on a widget is read by the widget's class (`AnswerableConversationWidget`) over one view of
shown widgets and their reactions; every other type follows one generic rule. Reaction sentences name the type and,
where two widgets of a type can be shown, their identifying value; they are never reworded.

**Reason.** Readers on the message set diverged — a dozen near-copies that disagreed about which row answered which
widget. A reader next to the class shares the sentence constants with the component, and a fixture pins both sides.

**Rejected.** Free-text matching outside the class; one generic rule for every widget (it cannot tell two widgets of a
type apart); a structured answer column (the owner's click is a message like any other and must replay).

**Would change if.** Clicks were stored as typed rows with a widget instance id.

## Signals are stored apart from owner text

**Rule.** A model-visible message the owner does not see is a SIGNAL row stored with role system and a source; only
the model's wire maps it to a user entry prefixed `[SIGNAL:<source>]`. There is no visibility axis.

**Reason.** Stored as a user row, every reader keyed on the role mistook it for the owner (counters, fact harvesting,
the chat bubble). One discriminator at one layer removes that class of bug; the source makes each new kind an addition.

**Rejected.** A user role with a check at every reader; a mid-conversation system message on the wire (unreliable
across providers); a visibility field now (one value, no consumer).

**Would change if.** A signal had to be shown to the owner; then a visibility field comes with its first consumer.

## A signal source is an enum that answers questions

**Rule.** A source is a case of a string-backed enum implementing `ConversationSignalSource`; the loop asks it basic
questions (speaker, starts a turn, owes work, posted by the client…) and derives the rest. A domain declares its own
enum in its own folder.

**Reason.** Lists of source names in each reader drifted apart; a question per property makes every reader agree, and
`isPostedByTheClient()` turns the security gate into a declaration.

**Rejected.** Constants on the message class with lists per reader; one enum the core edits for every domain.

**Would change if.** A source needed per-conversation behaviour that no static property can express.

## One composable wire type

**Rule.** The kit is one wire type, `ComposedView`, whose payload is a component tree; node types are classes, not wire
names. Domain widgets stay as they are and may be embedded.

**Reason.** The model answers any data shape with the same kit; a chart and a table share state in one block; the wire
name list does not grow by every component.

**Rejected.** A wire type per component (dozens of permanent names, no shared state); inline data only (the copy
failure); a widget per domain question only (unreachable for every question nobody built a widget for).

**Would change if.** Compositions displaced domain widgets that answer better — then the teaching, not the type,
changes.

## The reader drops and reports, it never refuses

**Rule.** Reading a `ComposedView` keeps what is valid, drops the rest where it stands and reports a finding; a block is
never rejected whole (except over the source budget).

**Reason.** The owner sees the valid part, the model learns what failed, and no fine-grained refusal blocks an answer.

**Rejected.** Rejecting invalid blocks (the owner sees nothing); silently ignoring invalid parts (the model never
learns).

**Would change if.** Partial trees misled owners more than missing ones.

## Tool numbers are bound and snapshotted, references are minted

**Rule.** A value from a tool result is bound with `$from` and resolved when the row is stored, with its state
written into the block; an entity reference is minted by the platform per listed row and validated when stored.

**Reason.** Copied numbers drift (the reshape failure); a snapshot makes a replay show exactly what the owner
saw without reading again; a reference the model invents opens nothing or the wrong entity.

**Rejected.** Resolving at render time (replays change, rights differ per reader); trusting a resolved value the model
typed; references by name.

**Would change if.** Tool results became addressable, immutable resources a client could read directly.

## The client reports what did not render

**Rule.** The renderer reports per block mounted, empty or failed; a message with a block that did not mount sends
one signal that owes no work, at most once, never for a replay.

**Reason.** A model that cannot see the screen otherwise claims "see the widget above" over nothing.

**Rejected.** Validating every block on the backend only (a component can fail at render); a report that starts a turn
(it would make the agent talk about rendering on its own).

**Would change if.** Rendering moved to the backend.

## The realtime signal is a ping

**Rule.** The change signal carries a counter, a message id and a status, never content; the package ships no transport.

**Reason.** Content in a push channel bypasses the read rights and duplicates the REST read; a ping can be lost without
harm.

**Rejected.** Streaming message content over the push channel; a transport in the package (every host has its own).

**Would change if.** The package shipped a streaming read with the same rights as the REST read.

## Activity lines only while someone watches

**Rule.** Lines are narrated by a small model on demand, cached per element, and pushed only while a reader is stamped.

**Reason.** Narration costs a model call per batch; unattended runs need none.

**Rejected.** Narrating every step of every run; showing tool names and arguments (meaningless and possibly sensitive
to the owner).

**Would change if.** Narration became free, or the tool calls themselves carried owner-facing descriptions.
