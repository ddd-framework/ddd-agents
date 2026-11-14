---
name: ToolTrace
emitter: frontend
summary: Collapsible trace of the tool calls behind an answer (debug/transparency).
description: Muted, italic single-line trace of a `tool_use` assistant part (e.g. `→ Opened Example Tasks`). Payload-only, renders on both live delivery and history replay, and never executes the tool.
---

# ToolTraceConversationWidget

## Description

Renders a muted single-line label for a `tool_use` part so the user can see which tool
the agent invoked, without re-running it. Renders identically on live delivery and on
history replay — it is purely presentational.

## Payload

```json
{
  "name": "Navigate",
  "input": { "destination": "APP.NAV.EXAMPLE.TASKS" }
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | no | Tool name. Falls back to `"Tool"` when missing/non-string. |
| `input` | object | no | Tool input. Only read when `name === "Navigate"`. |

## Label resolution

- `name === "Navigate"` **and** `input.destination` is a non-empty string → the last
  dot-segment of the destination is humanized: `APP.NAV.EXAMPLE.TASKS` → `Example Tasks`.
- Otherwise → the raw `name` (or `"Tool"`).

Output is `→ Opened <label>`.

## States

- Non-JSON / non-object payload, or unresolvable label → renders nothing (`null`).

## Layout

```
→ Opened Example Tasks
```

Single inline `<span>`, 12px, italic, subtle text color.
