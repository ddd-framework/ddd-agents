---
name: MultiChoice
emitter: none
status: Not implemented — nothing emits it yet; analysis of whether implementing it is worthwhile pending, possibly useful
summary: Generic multi-select picker with "Select all" and "Confirm N selections".
description: Generic multi-select picker. Renders a labelled list of options with checkboxes plus a "Select all" toggle and a "Confirm N selections" button. Emits a follow-up chat message with the chosen labels.
---

# MultiChoiceConversationWidget

## Description
Generic, payload-driven multi-select directive. Used wherever the assistant needs the user to pick one or more items from a finite list and confirm — e.g. business attributes, opening-hour days, GBP service flags, social-channel picks.

Visual: heading (optional title + subtitle), then a vertical stack of clickable rows each carrying a label, optional sublabel and a checkbox. A footer holds "Select all" / "Clear all" + a primary "Confirm {count} selections" button. Once confirmed the directive locks (no further toggling) and emits the configured chat message.

## Data structure
JSON payload:
```ts
{
  title?: string,
  subtitle?: string,
  options: Array<{
    label: string,           // visible label
    sublabel?: string,       // small caption under the label
    value: string,           // unique id, used in confirm action
    selected?: boolean,      // pre-checked when true
  }>,
  selectAllLabel?: string,           // default: "Select all"
  confirmLabelTemplate?: string,     // default: "Confirm {count} selection" (one key, plural via its MANY value:
                                     // "Confirm 1 selection" / "Confirm 2 selections") — {count} is replaced; a custom
                                     // template has no plural form, so word it count-neutral ("Selected: {count}")
  confirmAction?: string,            // default: "Confirmed: {values}" — {values}/{count} replaced before send
}
```

Markdown shape:
```md
::MultiChoice{payload='{"title":"…","options":[{"label":"…","value":"…"}]}'}
```

## When to use
- User needs to pick one or more items from a known, finite set and explicitly confirm.
- Skip for single-pick prompts (use a single-select directive) and for free-text answers.
- Skip when each pick should fire its own action immediately — `MultiChoiceConversationWidget` defers until confirm.

## ASCII layout (inline)
```
Title (optional)
Subtitle (optional)

┌────────────────────────────────────────────┐
│ Label A                              [✓]   │
│ Currently not added                        │
└────────────────────────────────────────────┘
┌────────────────────────────────────────────┐
│ Label B                              [ ]   │
└────────────────────────────────────────────┘
…

[ Select all ]   [ Confirm N selections ]
```
