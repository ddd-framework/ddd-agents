---
name: WebSearchResults
emitter: agent
canvas: webSearchResults
summary: References of the agent's own web research answer — the pages it used as link chips with site icons, placed under its prose answer. DATA-COMPLETE.
description: Renders the references of a web research answer the agent wrote itself — one link chip per page it used (the site's favicon and name, the page title on hover). The agent writes the answer as prose ABOVE the widget; the widget carries only `query` and `sources`. Legacy messages with an `answer` field render the former answer card.
---

# WebSearchResultsConversationWidget

## Description
The references of a web research answer. The agent writes its answer as prose in the message and puts this
widget on its own line UNDER it, carrying the pages it actually used. Each page renders as a link chip: the site's
favicon, the site name, the full page title on hover; a click opens the page in a new tab.

## Data structure — DATA-COMPLETE (no re-fetch)
**This widget does NOT fetch anything.** It renders entirely from the payload. The `sources` are copied character for
character from the `searchTheWeb` / `extractInformationFromWebPagesWithFastLLM` tool results.
```ts
interface WebSearchResultsPayload {
  query: string;                       // what was researched
  provider?: string;                   // PERPLEXITY
  sources: { url: string; title?: string }[];  // the pages the answer used, in order
  // legacy (older stored messages): title, display, answer — a payload with `answer` renders the answer card
}
```
Concrete payload example:
```json
{
  "query": "BMW iX M60 vs iX M70 chassis and acceleration",
  "provider": "PERPLEXITY",
  "sources": [
    { "url": "https://www.press.bmwgroup.com/deutschland/article/detail/T0447680DE/der-neue-bmw-ix", "title": "Der neue BMW iX" },
    { "url": "https://fastestlaps.com/models/bmw-ix-m60", "title": "BMW iX M60 specs, 0-60, quarter mile" }
  ]
}
```

## When to use
- After a web research answer on a screen: the answer as prose first, then this widget on its own line with the pages
  the answer used. Never the answer inside the widget, never a page the tools did not return.

## ASCII layout
```
<the agent's answer, prose, tables>

Sources
 (🌐 press.bmwgroup.com) (🌐 fastestlaps.com) (🌐 autobild.de)
```
