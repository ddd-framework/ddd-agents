---
name: MailDraftPreview
emitter: tool
summary: The exact mail of a send step as one compact preview (from, to, subject, body) with one send button, or the mail as sent.
description: Written by the mail tool (never by the model) before a send step that waits for the owner's yes (status DRAFT) and after the mail went out (status SENT). Shows the sender, the recipients, the subject and the body rendered as markdown (lists and tables read as one; a long body folds with "Show more"). A DRAFT carries one button "Send"; the click posts the button's label, translated as the owner reads it, as the owner's message, which is the confirmation of exactly this mail. The button goes away once a later row carries the SENT preview of the same contentHash, or once a later owner message answered the preview without the click. A SENT preview shows a "Sent" chip and no button. Without a recipient in the block the widget renders nothing.
---

# MailDraftPreviewConversationWidget

## Description
A card that reads like the mail itself: the header fields From (when known), To, Cc and Bcc (when set) and Subject in
a label column and a value column, a hairline, then the body as markdown (tables compact, ruled and banded). A body
taller than 240px folds under a fade and offers "Show more" / "Show less".

`DRAFT`: one button "Send" under the body. The click calls the chat's `sendMessageToChat` with the button's label,
translated through the app's texts, so the owner's message is the word they clicked. While the host takes the message
and until the `SENT` row arrives the button stays, busy and disabled ("Sending…"); a send the host refuses (`false`)
leaves it usable. Without a click the button shows only while the message is interactive and no later owner message
exists. It goes away for good when a later row carries a `MailDraftPreview` block with status `SENT` and the same
`contentHash`.

`SENT`: a "Sent" chip beside the header, no button.

Every interface text (field labels, "Send", "Sending…", "Sent", "Show more", "Show less") is an interface key; the
mail's own fields are the owner's data and are shown as written. In an e-mail capture the body is shown whole and
there is no button.

## Data structure
```ts
interface MailDraftPreviewPayload {
  status: "DRAFT" | "SENT"; // DRAFT waits for the owner's yes; anything other than SENT reads as DRAFT
  connectionId?: number;    // the connection the mail goes through
  from?: string;            // the connection's sending address or name
  to: string[];             // the recipients (required, at least one)
  cc?: string[];            // omitted when empty
  bcc?: string[];           // omitted when empty
  subject?: string;
  bodyText?: string;        // the body as written: markdown
  contentHash?: string;     // the hash of the exact send step; binds the SENT row to its DRAFT
}
```

## App duty
None beyond the chat: the send goes through the conversation's `sendMessageToChat`. The backend counts the owner's
message as the confirmation only right after the `DRAFT` row with the step's `contentHash`.
