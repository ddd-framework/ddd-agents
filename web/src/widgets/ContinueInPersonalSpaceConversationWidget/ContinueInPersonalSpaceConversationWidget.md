---
name: ContinueInPersonalSpace
emitter: tool
summary: Offer to continue a request in the owner's personal space, written when a connection only the owner may use is refused in a conversation others read.
description: Written by the backend (never by the model) when the owner asks, in a conversation that other people read, for something only a connection of their own may answer. Shows one line saying so and a button "Continue in your personal space"; the click hands the request to the app (AgentsEnvironment.startConversationInPersonalSpace), which starts a new conversation in the owner's personal space with the request as its first message. Nothing goes back into the conversation the widget sits in. Without the app hook, or without a request in the block, the widget renders nothing.
---

# ContinueInPersonalSpaceConversationWidget

## Description
A notice with a lock icon ("Only you may use this connection, and others read this conversation.") and one button,
"Continue in your personal space". The button is usable once: the click calls the app's
`startConversationInPersonalSpace(request)` and disables itself. The app decides how the new conversation opens (its
space picker, its window). Both texts are interface keys, translated through the app's texts.

## Data structure
```ts
interface ContinueInPersonalSpacePayload {
  request: string;   // the owner's request, the first message of the new conversation (required; empty = nothing renders)
  reason?: string;   // the backend's English reason; not shown
}
```

## App duty
Set `startConversationInPersonalSpace` in the environment. Without it the widget renders nothing, so an app that has
no personal space shows no dead button.
