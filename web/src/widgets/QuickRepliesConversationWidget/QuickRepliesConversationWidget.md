---
name: QuickReplies
emitter: agent
summary: Short answer pills under a closed question — one click sends that option's text as the owner's message.
description: The pill answers to a closed question (a backend hard rule) — two to five SHORT options in the owner's language, each a complete answer the owner could also type. A click posts the option's text as the owner's own message; the card locks once the send is confirmed, and goes inert once a newer owner message exists (the conversation moved past the question).
---

# QuickRepliesConversationWidget

## Description
The agent asks a CLOSED question in prose, then offers the answers as pills instead of leaving the owner to type one. Renders a row of short buttons under the question; a click sends that button's exact text as the owner's chat message — a shortcut for typing the same words, never a separate action channel.

Single pick only (compare `MultiChoiceConversationWidget`, which defers to an explicit multi-select confirm). Locks after the click is confirmed sent; a rejected send (closed conversation, an in-flight start) leaves the pills clickable again. Once a NEWER owner message exists in the conversation — the owner typed past it, or answered a later question — the card goes inert (rendered disabled) so a stale pick can never answer the wrong question.

Every option MUST be a complete answer the owner could send verbatim — never a template with a blank (e.g. "Yes, we deliver to these areas: …"). The widget enforces this as a backstop: an option containing an ellipsis (`…`/`...`), a bracketed or underscore blank (`[...]`, `[…]`, `___`), or ending in a colon is a "placeholder option" — clicking it never sends; instead the stripped text is placed in the composer (with a trailing space) and focused, so the owner types the missing part themselves.

## Data structure
JSON payload:
```ts
{
  options: string[], // 2 to 5 entries, each trimmed, non-empty, unique case-insensitively
}
```

Validation (an invalid payload renders nothing):
- `options` must be an array.
- 2 to 5 entries.
- Each entry a non-empty string after trimming.
- No two entries equal case-insensitively (not silently deduplicated — a payload that violates this is dropped).
- An entry longer than 60 characters is shown truncated with an ellipsis; the click still sends the FULL text.

Markdown shape (the fence form):
```widget
{ "type": "QuickReplies", "options": ["Ja, veröffentlichen", "Jetzt nicht"] }
```

## When to use
- Right under a closed (yes/no/either-or) question, so the owner can answer with one click instead of typing.
- Each option must be a short, complete answer the owner could also type verbatim — not a label for a longer action.
- Skip for an open-ended question (free text is the only sensible answer) and for multi-select (`MultiChoiceConversationWidget`).

## ASCII layout (inline)
```
Möchtest du die Änderungen jetzt veröffentlichen?

   [ Ja, veröffentlichen ]   [ Jetzt nicht ]
```

After a confirmed click, every pill disables and the chosen one stays highlighted:
```
Möchtest du die Änderungen jetzt veröffentlichen?

   [ Ja, veröffentlichen ]■  [ Jetzt nicht ]   (■ = chosen, both disabled)
```


## Platform-written widgets (`askOwner`)

The backend's `askOwner` meta-tool renders the model's own closed question as a QuickReplies tool widget under the
final text. Its fence carries extra flat fields the widget ignores and the platform reads back: `question` (the
model's question text), `questionKey` (a catalogue key such as `serveModel`, `draftApproval`), `optionMeanings`
(one catalogue meaning per option, same order) and `descriptions` (one optional explanation per option, `null`
where none). The click still posts the option label as the owner's message — that label is the platform's read-back
key. A multi-select question renders as `MultiChoice` with `confirmAction: "{values}"` (the picked labels joined by
", ").
