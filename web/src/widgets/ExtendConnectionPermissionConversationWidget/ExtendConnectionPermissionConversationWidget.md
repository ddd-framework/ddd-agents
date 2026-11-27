---
name: ExtendConnectionPermission
emitter: tool
summary: Offer to open a connection's settings when a step needs a level or provider permission the connection does not have yet.
description: Written by the backend (never by the model) when a step needs a level the agent connection does not have, or a permission the provider has not granted yet. Shows one line saying so and a button "Extend the permission"; the click hands the connection, capability and level to the app (AgentsEnvironment.openConnectionPermissions), which opens its connection settings with that permission preselected. The level takes effect only once the owner raised it there. Without the app hook, or without a connection, capability and level in the block, the widget renders nothing.
---

# ExtendConnectionPermissionConversationWidget

## Description
A notice with an open-lock icon ("This step needs a permission the connection does not have yet.") and a button
"Extend the permission". Each click calls the app's `openConnectionPermissions({connectionId, capability, level})`;
the button stays usable, since the owner may leave the settings unchanged. Both texts are interface keys, translated
through the app's texts; the block's `reason` (the backend's English sentence for logs and the model) is deliberately
not shown.

## Data structure
```ts
interface ExtendConnectionPermissionPayload {
  connectionId: number;  // the connection (required, > 0)
  capability: string;    // MAIL, CALENDAR, CONTACTS, FILES or CHAT (required)
  level: string;         // the level the step needs (required)
  reason?: string;       // the backend's English reason; not shown
}
```

## App duty
Set `openConnectionPermissions` in the environment. Without it the widget renders nothing.
