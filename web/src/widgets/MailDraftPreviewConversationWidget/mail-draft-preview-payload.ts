import { parseWidgetJsonObject } from "../../renderer/widget-fence-json";
import { WIDGET_FENCE_LANG } from "../../renderer/widget-fence-syntax";

/** The wire name the backend writes (`MailDraftPreviewConversationWidget::WIDGET_TYPE`). */
export const MAIL_DRAFT_PREVIEW_WIDGET_TYPE = "MailDraftPreview";

/** `DRAFT`: the send step waits for the owner's yes. `SENT`: the mail went out. */
export type MailDraftPreviewStatus = "DRAFT" | "SENT";

/** The fields of a `MailDraftPreview` block. */
export interface MailDraftPreviewPayload {
  status: MailDraftPreviewStatus;
  /** The connection the mail goes through; null when the block carries none. */
  connectionId: number | null;
  /** The connection's sending address or name; empty when the block carries none. */
  from: string;
  /** At least one recipient. */
  to: string[];
  cc: string[];
  bcc: string[];
  subject: string;
  /** The body as written: markdown (lists and tables render). */
  bodyText: string;
  /** The hash of the exact send step; empty when the block carries none. */
  contentHash: string;
}

const readText = (value: unknown): string => (typeof value === "string" ? value : "");

/** The non-empty addresses of a field; anything that is not a list of strings counts as none. */
const readAddresses = (value: unknown): string[] =>
  Array.isArray(value)
    ? value
        .filter((address): address is string => typeof address === "string")
        .map((address) => address.trim())
        .filter((address) => address !== "")
    : [];

const readConnectionId = (value: unknown): number | null => {
  const connectionId = typeof value === "number" ? value : Number(value);
  return Number.isInteger(connectionId) && connectionId > 0 ? connectionId : null;
};

/** The fields of a decoded block, or null when it names no recipient. */
const readMailDraftPreviewFields = (fields: Record<string, unknown>): MailDraftPreviewPayload | null => {
  const to = readAddresses(fields.to);
  if (to.length === 0) return null;
  return {
    status: fields.status === "SENT" ? "SENT" : "DRAFT",
    connectionId: readConnectionId(fields.connectionId),
    from: readText(fields.from).trim(),
    to,
    cc: readAddresses(fields.cc),
    bcc: readAddresses(fields.bcc),
    subject: readText(fields.subject),
    bodyText: readText(fields.bodyText),
    contentHash: readText(fields.contentHash),
  };
};

/**
 * The payload of a block, or null when it does not decode or names no recipient. Lenient on the rest: an unknown
 * status reads as `DRAFT`, a missing text as empty, non-string addresses are dropped.
 */
export const parseMailDraftPreviewPayload = (payload: string | undefined): MailDraftPreviewPayload | null => {
  if (typeof payload !== "string") return null;
  let parsed: unknown;
  try {
    parsed = JSON.parse(payload);
  } catch {
    return null;
  }
  if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) return null;
  return readMailDraftPreviewFields(parsed as Record<string, unknown>);
};

/** Every ```widget fence of a stored message text; group 1 is the block JSON. */
const WIDGET_FENCE_GLOBAL = new RegExp(`\`\`\`[ \\t]*${WIDGET_FENCE_LANG}[ \\t]*\\n([\\s\\S]*?)\\n[ \\t]*\`\`\``, "g");

/**
 * Whether a stored message text carries the `SENT` preview of the step with this `contentHash` — the row the mail
 * tool writes once that exact mail went out. False for an empty hash.
 */
export const isSentMailDraftPreviewOf = (messageText: string | null | undefined, contentHash: string): boolean => {
  if (typeof messageText !== "string" || contentHash === "" || !messageText.includes(MAIL_DRAFT_PREVIEW_WIDGET_TYPE)) {
    return false;
  }
  for (const fenceMatch of messageText.matchAll(WIDGET_FENCE_GLOBAL)) {
    const fields = parseWidgetJsonObject(fenceMatch[1]);
    if (
      fields !== undefined &&
      fields.type === MAIL_DRAFT_PREVIEW_WIDGET_TYPE &&
      fields.status === "SENT" &&
      fields.contentHash === contentHash
    ) {
      return true;
    }
  }
  return false;
};
