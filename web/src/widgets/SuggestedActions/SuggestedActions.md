---
name: SuggestedActions
emitter: frontend
summary: Quick-reply pill buttons under an AI message; click sends a predefined prompt.
description: Inline row of muted pill buttons rendered under an AI message. Each pill is a quick-reply — clicking it sends the predefined `userAction` text back into the chat as if the user typed it. Lightweight quick-reply pattern; no icons, no descriptions, no flags.
---

# SuggestedActions

## Description
Quick-reply pills shown under an assistant turn. Parses `options` (JSON array of `{displayText, userAction}`), renders muted component-library `Button`s in a single horizontal flex row. Click → `sendMessageToChat(userAction)` → the chat behaves as if the user typed `userAction`. Returns `null` on parse / shape failure (warns to console).

Visual contract:
- Single horizontal row, compact muted buttons.
- `displayText` is the **short** label the user sees on the pill (verb-led, ~1–4 words).
- `userAction` is the **full** message that gets injected into the chat on click — phrase it as something a real user would naturally type, can be a complete sentence.
- No icons, no subtitles, no recommended / destructive variants.

## Data structure
Single prop `options` — a non-empty array of `{displayText, userAction}` objects.
```ts
interface SuggestedActionsProps {
  options: {
    displayText: string;  // required, short pill label (~1–4 words, verb-led)
    userAction: string;   // required, full user message sent to chat on click
  }[];                    // required, non-empty
}
```
The renderer JSON-stringifies prop objects automatically, so mock / backend authors supply a plain array — no double-encoding needed.

Concrete `options` value:
```json
[
  { "displayText": "Reply now",   "userAction": "Help me reply to my recent reviews" },
  { "displayText": "Draft it",    "userAction": "Draft a weekend promo post for Google" },
  { "displayText": "Skip for now","userAction": "Skip these for now" }
]
```

Markdown shape:
```md
::SuggestedActions{options='[{"displayText":"Reply now","userAction":"Help me reply to my recent reviews"}]'}
```

## When to use
- AI ends a turn with 2–6 obvious conversational follow-ups the user is likely to tap instead of typing.
- Each option must be **a message the user would say**, not an action that opens a UI / mounts a widget / triggers a side effect.
- `displayText` reads like a chat-reply chip ("Show me how", "Draft it for me", "Not now").
- `userAction` reads like a complete user turn — specific enough that the assistant can act on it without follow-up clarification.
- Options that would need descriptions, titles, icons or a recommended flag are written as prose — there is no card-stack widget.
- Skip when an option must open a canvas, mount a widget, or run a side effect → pick the matching dedicated widget.
- Skip when there is exactly one obvious next step — just ask the user directly.

## ASCII layout (inline)
```
... AI message text above ...

[ Reply now ]  [ Draft it ]  [ Add photos ]  [ Sync hours ]  [ Skip for now ]
   muted          muted          muted           muted            muted
```
