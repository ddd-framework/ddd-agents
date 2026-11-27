/**
 * The message text a send with attachments stores: the typed text plus one reference line per attachment. The
 * conversation routes of the package take the text only, so a binding carries the attachments in it; the chat shows the
 * optimistic owner message with the same text (`AgentsConversationClient.composeSentMessageText`).
 */
export const composeAttachmentMessageText = (text, attachments) => attachments?.length
    ? `${text}\n\n${attachments
        .map((attachment) => `[Attached file: ${attachment.fileName ?? "file"} — ${attachment.publicUrl ?? ""}]`)
        .join("\n")}`
    : text;
