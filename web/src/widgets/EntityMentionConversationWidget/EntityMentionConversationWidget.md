---
name: EntityMention
emitter: customer
summary: Composer widget: the entity (a competitor, a review, a keyword) the OWNER asked about from a detail panel's "Ask about this", sent inside their own message and echoed read-only above their prose. Never emitted by the agent. Declares `composerComponent` (`EntityMentionComposerWidget`).
description: Read-only echo of the entity the owner picked with "Ask about this" in an entity detail panel, rendered above their OWN message prose. The owner's composer writes this fence — the agent never emits it; the backend reads which entity the question is about from it.
---

# EntityMentionConversationWidget

## Description
This widget is written by the OWNER's composer, never by the agent (ChatGPT's "Anschlussfrage stellen").
The `entityDetail` canvas shows one entity (opened by an `EntityLink`, a `Map` point or a button of a `ComposedView`
tree); its **"Ask about this"** button (`EntityMentionAskButton/`, hook `shared/use-entity-mention-composer.ts`) puts
the entity into the chat input as an `EntityMention` **composer widget** (`ConversationWidgetContext.composerWidgets`,
rendered by `EntityMentionComposerWidget` as a chip with a ✕). It sends nothing: the owner writes the question. On send,
`buildMessageTextWithComposerWidgets` appends one fence per mentioned entity to the message text;
`ConversationMessageUser` extracts it again (`splitComposerWidgetFencesFromMessageText`) and renders THIS component —
a read-only card (the entity type's icon, "Asking about", the name) — above the prose, right-aligned.

The backend reads the fence off the owner's own message as `EntityMentionConversationWidget`
(`app/src/Domain/AI/Entities/Conversations/Widgets/`): `entityType` + `entityId` name the entity
(`buildEntityReferenceRow()` gives the row the entity type's `EntityReferenceResolver` checks the read rights of);
`label` is only what the panel showed and identifies nothing. One widget per entity, at most 5 per message (a sixth
"Ask about this" takes the place of the oldest mention); the same entity twice is one mention.

A payload that names no entity (no valid `entityType` / `entityId`) renders nothing and reports `failed INVALID_PROP`.

## Data structure
```ts
interface EntityMentionPayload {
  entityType: string; // the resolver's ENTITY_TYPE: "competitor" | "review" | "keyword" | …
  entityId: string; // the id the backend resolved the reference to (a row id or an external id)
  label?: string; // the entity's name as the panel showed it, at most 120 characters
  // the key of the entity's agent space, under the block field the app declares
  // (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`); omitted = the chat's agent space
}
```

## Fence example
```widget
{"type": "EntityMention", "entityType": "competitor", "entityId": "8812", "label": "Altes Gasthaus Leve"}
```

## When to use
Never emitted by the agent — the owner's composer writes it with the detail panel's "Ask about this" button; this doc exists for the registry and the backend reader, not as an authoring instruction.

## ASCII layout
Right-aligned, above the owner's own message:
```
              ┌──────────────────────────┐
              │ [▤]  Asking about         │
              │      Altes Gasthaus Leve  │
              └──────────────────────────┘
                 Why is their rating better than mine?
```
