/**
 * The `WritingDraft` payload (DATA-COMPLETE: the model writes the whole draft). The parser is alias-tolerant (weak
 * models rename fields while they write, and ChatGPT's own `WritingArtifact` shape — `variant`, `content.text` — may be
 * copied), caps every string, and never throws: malformed JSON gives `isValidPayload: false`, which the widget reports
 * as `failed`; a payload without a text reports `empty`.
 */

/** The body length shown at most (the backend's `WritingDraftConversationWidget::MAX_BODY_LENGTH`); the rest is cut. */
export const MAX_DRAFT_BODY_LENGTH = 20000;

const MAX_TITLE_LENGTH = 160;
const MAX_RECIPIENT_LENGTH = 320;
const MAX_SUBJECT_LENGTH = 240;

/** The kinds the widget labels; the backend's `WritingDraftConversationWidget::KINDS`. */
export type WritingDraftKind = "email" | "letter" | "post" | "reply" | "message" | "other";

export interface WritingDraftPayload {
  /** False when the payload is not a JSON object at all. */
  isValidPayload: boolean;
  kind?: WritingDraftKind;
  title?: string;
  recipient?: string;
  subject?: string;
  /** The draft as Markdown; "" when the model wrote none. */
  body: string;
  /** A body field is there but holds no text (an object, a number, a list) — reported `failed`, not `empty`. */
  isBodyUnreadable: boolean;
}

const KIND_KEYS = ["kind", "variant", "draftType", "draft_type", "category"] as const;
const TITLE_KEYS = ["title", "heading", "name"] as const;
const RECIPIENT_KEYS = ["recipient", "recipients", "to", "addressee", "recipientEmail", "email"] as const;
const SUBJECT_KEYS = ["subject", "subjectLine", "subject_line"] as const;
const BODY_KEYS = ["body", "content", "text", "markdown", "message", "draft"] as const;

/** The words a model writes for "no recipient yet" — shown as an empty field, never as a recipient. */
const NO_RECIPIENT_WORDS = new Set(["not specified", "unspecified", "unknown", "n/a", "none", "-", "tbd"]);

const KIND_ALIASES: Record<string, WritingDraftKind> = {
  email: "email",
  "e-mail": "email",
  mail: "email",
  letter: "letter",
  brief: "letter",
  post: "post",
  socialpost: "post",
  social: "post",
  reply: "reply",
  response: "reply",
  answer: "reply",
  message: "message",
  sms: "message",
  chat: "message",
  dm: "message",
  other: "other",
  draft: "other",
  memo: "other",
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

const readCappedString = (value: unknown, maxLength: number): string | undefined => {
  if (typeof value !== "string") return undefined;
  const trimmedValue = value.trim();
  return trimmedValue === "" ? undefined : trimmedValue.slice(0, maxLength);
};

const readFirstString = (record: Record<string, unknown>, keys: readonly string[], maxLength: number) => {
  for (const key of keys) {
    const stringValue = readCappedString(record[key], maxLength);
    if (stringValue !== undefined) return stringValue;
  }
  return undefined;
};

/** `email`, `E-Mail`, `social_post` … → one of the kinds; another word → `other`; nothing → undefined. */
const readKind = (record: Record<string, unknown>): WritingDraftKind | undefined => {
  const kindWord = readFirstString(record, KIND_KEYS, 40);
  if (kindWord === undefined) return undefined;
  return KIND_ALIASES[kindWord.toLowerCase().replace(/[\s_]/g, "")] ?? "other";
};

/** A recipient string, or a list of them joined by a comma; a "not specified" placeholder is no recipient. */
const readRecipient = (record: Record<string, unknown>): string | undefined => {
  for (const key of RECIPIENT_KEYS) {
    const rawValue = record[key];
    const recipient = Array.isArray(rawValue)
      ? readCappedString(
          rawValue.filter((entry): entry is string => typeof entry === "string").join(", "),
          MAX_RECIPIENT_LENGTH,
        )
      : readCappedString(rawValue, MAX_RECIPIENT_LENGTH);
    if (recipient !== undefined) return NO_RECIPIENT_WORDS.has(recipient.toLowerCase()) ? undefined : recipient;
  }
  return undefined;
};

/** The body as written (outer blank lines trimmed, inner kept); ChatGPT's `content: {format, text}` is read too. */
const readBody = (record: Record<string, unknown>): string => {
  for (const key of BODY_KEYS) {
    const rawValue = record[key];
    const bodyValue = isRecord(rawValue) ? (rawValue.text ?? rawValue.markdown ?? rawValue.body) : rawValue;
    if (typeof bodyValue === "string" && bodyValue.trim() !== "") {
      return bodyValue
        .replace(/^\s*\n/, "")
        .trimEnd()
        .slice(0, MAX_DRAFT_BODY_LENGTH);
    }
  }
  return "";
};

/** A body field that is present but no string the widget can read (`null` and `""` count as "no text", not as this). */
const hasUnreadableBodyField = (record: Record<string, unknown>): boolean =>
  BODY_KEYS.some((key) => record[key] !== undefined && record[key] !== null && typeof record[key] !== "string");

export const parseWritingDraftPayload = (payload: string | undefined): WritingDraftPayload => {
  let parsedPayload: unknown;
  try {
    parsedPayload = JSON.parse(payload ?? "");
  } catch {
    return { isValidPayload: false, body: "", isBodyUnreadable: false };
  }
  if (!isRecord(parsedPayload)) return { isValidPayload: false, body: "", isBodyUnreadable: false };
  const kind = readKind(parsedPayload);
  const title = readFirstString(parsedPayload, TITLE_KEYS, MAX_TITLE_LENGTH);
  const recipient = readRecipient(parsedPayload);
  const subject = readFirstString(parsedPayload, SUBJECT_KEYS, MAX_SUBJECT_LENGTH);
  const body = readBody(parsedPayload);
  return {
    isValidPayload: true,
    body,
    isBodyUnreadable: body === "" && hasUnreadableBodyField(parsedPayload),
    ...(kind ? { kind } : {}),
    ...(title ? { title } : {}),
    ...(recipient ? { recipient } : {}),
    ...(subject ? { subject } : {}),
  };
};
