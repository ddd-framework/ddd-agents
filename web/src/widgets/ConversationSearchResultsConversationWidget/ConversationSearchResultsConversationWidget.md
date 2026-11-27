---
name: ConversationSearchResults
emitter: agent
canvas: conversationSearchResults
summary: Grouped results of a search across earlier conversations. DATA-COMPLETE; ≤5 groups inline, more → canvas.
description: Renders the result of searchConversations (cross-chat semantic recall) — matches grouped by conversation (title · date · matched message excerpts with the query bolded), each with an arrow to jump to that chat. Few groups inline, many in the canvas side panel.
---

# ConversationSearchResultsConversationWidget

## Description
Shows the matches from `searchConversations` grouped by conversation — reuses the same `AgentSearchResults` view the chat's own search panel uses. Each group is a card: conversation title (or "Untitled chat") · relative date, then its matched message rows (You / the agent + excerpt, query terms highlighted), with an arrow to open that conversation. Few groups render inline; many open in the canvas side panel.

## Data structure — DATA-COMPLETE (no re-fetch)
**Unlike a self-hydrating widget, this widget does NOT fetch anything.** It renders entirely from the payload. The agent names the search's tool call in `groupsFromToolCallId`; the platform writes that call's `groups` (and the `query`, when the block's is empty) into the block when the message is stored — the search is never re-run, and the agent never copies the groups.
```ts
interface ConversationSearchResultsPayload {
  query: string;                       // the search query, used to highlight matched terms
  title?: string;                      // short LLM headline (user's language) shown on the collapsed canvas card; falls back to the query
  display?: "inline" | "canvas";       // OPTIONAL — normally OMIT; the widget auto-picks inline (few) vs canvas (many)
  groupsFromToolCallId?: string;       // written by the agent INSTEAD of `groups`: the id of the search's tool call; the platform fills `groups` from its stored result
  groups: {
    conversationId?: number;           // the source chat (the open-arrow target)
    title?: string;                    // the chat's title
    summary?: string;                  // the chat's summary recap (shown under the title)
    dateTime?: string;                 // "Y-m-d H:i:s" of the most-recent matched message
    hits: { role?: string; excerpt?: string; dateTime?: string; indexInConversation?: number; messageId?: number }[];
  }[];  // filled by the platform (see above); what the widget renders — dateTime per hit = that message's timestamp (shown on the row)
}
```
What the agent writes:
```json
{ "type": "ConversationSearchResults", "query": "facebook", "groupsFromToolCallId": "<id of the search's tool call>" }
```
What the widget receives after the platform filled it:
```json
{
  "query": "facebook",
  "groups": [
    { "conversationId": 1841, "title": "Create Facebook Post", "summary": "Drafted a first Facebook post for the shop.", "dateTime": "2026-06-20 14:03:11",
      "hits": [ { "role": "user", "excerpt": "connect fb", "dateTime": "2026-06-20 14:01:00" }, { "role": "user", "excerpt": "ok, create a post for fb", "dateTime": "2026-06-20 14:03:11" } ] },
    { "conversationId": 1799, "title": "Open Connections", "dateTime": "2026-06-18 09:21:00",
      "hits": [ { "role": "user", "excerpt": "in connections is facebook connected?", "dateTime": "2026-06-18 09:21:00" } ] }
  ]
}
```

## When to use
- After `searchConversations`, when the owner wants to SEE or open the matches — copy the block its result names (it carries `groupsFromToolCallId`; the platform fills `groups`) instead of re-listing them in prose.
- Few matches → they render inline; many → they open in the canvas side panel automatically (or set `display`).
- Skip for `searchThisConversation` (the current chat — answer inline in prose) and when the owner asked for a summary of what was discussed.

## ASCII layout (inline / canvas)
```
┌──────────────────────────────────────────────┐
│ Create Facebook Post                       →  │   ← group: title · date · open arrow
│   You   connect ⟦fb⟧                          │
│   You   ok, create a post for ⟦fb⟧            │
├──────────────────────────────────────────────┤
│ Open Connections                           →  │
│   You   in connections is ⟦facebook⟧ connected?│
└──────────────────────────────────────────────┘
   (⟦…⟧ = query terms highlighted; → jumps to that chat)
```
