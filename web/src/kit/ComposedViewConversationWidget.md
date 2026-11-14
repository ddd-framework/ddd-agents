---
name: ComposedView
emitter: agent
canvas: entityDetail, mapFullScreen
summary: The composable widget kit — a tree of layout, text, data, media and input components (KPI tiles, charts, tables, maps, tabs) with local state, computed values and bound data. The model composes it for any data shape no domain widget covers — a dashboard or overview of headline numbers, a comparison table, a chart over time, places on a map.
description: One widget fence of type ComposedView carries a component TREE — `view` (the root node with its children and named slots), `state` (local state, changed by the tree's inputs only), `data` (named datasets — inline rows with a source, a `$from` tool-result binding, a `$source` data source), `computed` (expressions over state, data and each other in a closed language) and `actions` (copy, resetState, openEntity, openUrl). The node types, their props and slots are the generated schema `conversation-markdown/widget-tree/composed-node-schema.json`; the frontend and the backend drop what does not fit it and report it.
---

# ComposedViewConversationWidget

## Description
The agent's display language: instead of one bespoke widget per domain question, the model composes a tree
of kit components — a KPI row over a line chart over a table, a comparison table, a map with a carousel — and binds
the values to tool results or writes researched values inline with their source. Inputs (tabs, sliders, selects)
change local state only; every chart, KPI and table is re-computed from that state without a chat turn.

## Data structure
```ts
interface ComposedViewPayload {
  title?: string;                                   // business content, the owner's language
  state?: Record<string, string | number | boolean | null>;
  data?: Record<string, unknown[] | { $from: { toolCall: string; path: string } } | { $source: { name: string; params?: object } }>;
  computed?: Record<string, string>;                // closed expression language
  view: { type: string; children?: Node[]; slots?: Record<string, Node[]>; [prop: string]: unknown };
  actions?: Record<string, { type: "copy" | "resetState" | "openEntity" | "openUrl"; [field: string]: unknown }>;
}
```
Bindings are objects, never string templates: `{"$state":"period"}`, `{"$computed":"total"}`, `{"$data":"rows"}`,
`{"$item":"x"}` (inside a node that repeats its children), `{"$from":{"toolCall":"call_7","path":"$.rows[*]"}}`,
`{"$source":{"name":"rankings","params":{"days":30}}}`. An event prop takes `{"set":"<state name>"}` or
`{"action":"<action name>"}`.

```json
{
  "type": "ComposedView",
  "title": "Visits",
  "state": { "period": "7d" },
  "data": { "rows": [{ "x": "Mo", "visits": 980 }, { "x": "Di", "visits": 1120 }] },
  "computed": { "total": "sum(rows, r => r.visits)" },
  "view": {
    "type": "Stack",
    "children": [
      { "type": "KpiTile", "label": "Visits this week", "value": { "$computed": "total" } },
      { "type": "Chart", "kind": "line", "rows": { "$data": "rows" }, "x": "x", "y": "visits" }
    ]
  }
}
```
(`Stack`, `KpiTile` and `Chart` are node types of the kit.) A `Widget` node embeds a domain widget only
when its class declares itself embeddable — none does yet, so a `Widget` node is dropped today, at the root as well.

## When to use
- The answer has a data shape (a comparison, a development over time, shares, a few headline numbers, places) and no
  loaded skill offers a domain widget for it.
- Not for a single number, a yes or no, advice or small talk; never on the phone surface.

## Components
Registered by folder: `components/<group>/<Name>.composed.tsx` exporting `composedViewComponent`. The core `Widget` node
(an embeddable domain widget) is built in; the other kit components are separate node types.
