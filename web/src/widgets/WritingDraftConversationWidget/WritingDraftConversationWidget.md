---
name: WritingDraft
emitter: agent
summary: Editable draft of a text the owner sends or publishes — an e-mail, letter, post, reply or message; the text lives ONLY in the block, your line names it. An optional recipient and subject, the body as formatted text; the owner edits it in place, copies it and opens an e-mail draft in their own mail program. Nothing is sent from the widget.
description: Shows a text the agent drafted for the owner to send or publish themselves, the way ChatGPT's writing block does — a kind label (e-mail, letter, post, reply, message draft) and the headline, for an e-mail or a letter the recipient and subject rows, then the text formatted from Markdown (paragraphs, emphasis, lists, quotes, safe http links; no images, no raw HTML). The owner can edit the recipient, the subject and the text in place, go back to the original draft, copy the text as plain text, and for an e-mail open it in their own mail program through a mailto link with recipient, subject and text. Every edit stays in the browser — the widget sends nothing to the agent and nothing to anyone else, and says so while the owner edits. In a report e-mail it shows the draft read-only. DATA-COMPLETE, inline, not answerable.
---

# WritingDraftConversationWidget

## Description
A drafted text the owner will send or publish themselves: an e-mail to a supplier, a letter to a landlord, a post for
the shop window, a reply to a customer's message. The widget shows the kind label with the model's `title` under it
(only when it says more than the subject) above the card; the card reads like the message itself — for an e-mail or
a letter the recipient and subject as a mail header ("No recipient yet" muted when there is none), a hairline, then the
text formatted from Markdown — the safe subset of the kit's `Markdown` node: paragraphs, emphasis, lists, quotes, http(s) links in a new
tab; images, other link schemes and raw HTML are dropped. A post shows its character count at the bottom right.
The actions are small pills centred under the card, outside it.

The owner can:
- **Edit** — the recipient, the subject and the text turn into fields in the same card; "Done" shows the formatted
  text again, "Discard changes" restores the model's version. A line in the card says the changes stay on this
  screen and the assistant does not see them; an "Edited by you" marker shows while the text differs.
- **Copy** — the text as plain text (no Markdown syntax), the owner's edit included.
- **Open in mail** (e-mail drafts only) — a `mailto:` link with the recipient (only real e-mail addresses),
  the subject and the plain text. A text longer than a mail link carries reliably (~1,900 characters) opens the mail
  without the text and the widget tells the owner to copy it.

Nothing is sent and nothing is reported: the widget is **not answerable** (no reaction sentence, no signal) and awaits
nothing. No tool or gate reads what the owner did on it, and there is no send tool behind it — when the owner wants
the assistant to rework their edited version, they paste or describe it in the chat. A review reply, a social post or
any text one of our tools saves or publishes goes through that tool and its own widget, never through this one.

States: a block without a text renders one muted line and reports `empty` (`NO_DATA`); a payload that is not a JSON
object, or a `body` that is no text (an object, a list), renders one muted line and reports `failed` (`INVALID_PROP`). There is no loading state — nothing is fetched.
In a report e-mail the draft renders read-only, without the actions.

## Data structure — DATA-COMPLETE (the whole draft in the block)
```ts
interface WritingDraftPayload {
  kind?: "email" | "letter" | "post" | "reply" | "message" | "other"; // OMIT → a plain "Text draft"
  title?: string;      // the headline in the owner's language — "Anfrage an Bäckerei Müller"; OMIT → the subject
  recipient?: string;  // an e-mail address ("info@baeckerei-mueller.de", several comma-separated) or a name
  subject?: string;    // the subject line of an e-mail or a letter
  body: string;        // the draft text as Markdown (paragraphs with blank lines, **bold**, lists); at most 20,000 characters
}
```
Accepted aliases (weak models rename fields): `variant` / `draftType` for `kind` (`e-mail`, `mail`, `social`, `sms`
… are mapped); `to` / `recipients` (a list) / `addressee` for `recipient`; `content`, `text`, `markdown`, `message`
for `body`, also ChatGPT's `content: {"format": "markdown", "text": "…"}`; `heading` for `title`. A recipient written as
"not specified" / "unknown" counts as none. Never put three backticks into the text — they end the widget block.

E-mail draft:
```widget
{ "type": "WritingDraft", "kind": "email", "recipient": "info@baeckerei-mueller.de", "subject": "Kurze Anfrage", "body": "Guten Tag,\n\nich interessiere mich für Ihr Angebot und hätte gerne einige weitere Informationen zu Preisen, Verfügbarkeit und Konditionen.\n\nKönnten Sie mir die entsprechenden Unterlagen zusenden?\n\nVielen Dank und freundliche Grüße" }
```
Post draft:
```widget
{ "type": "WritingDraft", "kind": "post", "title": "Post zur Wiedereröffnung", "body": "**Wir sind wieder da!** Ab Montag, 3. November, haben wir wieder für euch geöffnet." }
```

## When to use
- When the owner asks for a text they will send or publish themselves — an e-mail, a letter, a message, a post or a reply ("Schreib mir eine E-Mail an meinen Lieferanten"): ONE WritingDraft with the whole text in `body` (Markdown), the `kind`, and for an e-mail the `recipient` (an e-mail address when the owner gave one) and the `subject`.
- Write a SHORT one-line prose intro FIRST ("Hier ist ein Entwurf für deine Anfrage:"), then the widget on its own line; do not paste the draft a second time in prose.
- Write the draft in the language of its reader, with the facts the owner gave — no invented prices, dates or names, no "[placeholder]" where the owner already said the detail; ask when a fact is missing.
- The widget sends nothing: a review reply, a post or a message that one of our tools saves or publishes goes through that tool and its own widget, never through a WritingDraft.
- Not for code, data, a table or a one-sentence answer.
