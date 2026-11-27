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
const KIND_KEYS = ["kind", "variant", "draftType", "draft_type", "category"];
const TITLE_KEYS = ["title", "heading", "name"];
const RECIPIENT_KEYS = ["recipient", "recipients", "to", "addressee", "recipientEmail", "email"];
const SUBJECT_KEYS = ["subject", "subjectLine", "subject_line"];
const BODY_KEYS = ["body", "content", "text", "markdown", "message", "draft"];
/** The words a model writes for "no recipient yet" — shown as an empty field, never as a recipient. */
const NO_RECIPIENT_WORDS = new Set(["not specified", "unspecified", "unknown", "n/a", "none", "-", "tbd"]);
const KIND_ALIASES = {
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
const isRecord = (value) => typeof value === "object" && value !== null && !Array.isArray(value);
const readCappedString = (value, maxLength) => {
    if (typeof value !== "string")
        return undefined;
    const trimmedValue = value.trim();
    return trimmedValue === "" ? undefined : trimmedValue.slice(0, maxLength);
};
const readFirstString = (record, keys, maxLength) => {
    for (const key of keys) {
        const stringValue = readCappedString(record[key], maxLength);
        if (stringValue !== undefined)
            return stringValue;
    }
    return undefined;
};
/** `email`, `E-Mail`, `social_post` … → one of the kinds; another word → `other`; nothing → undefined. */
const readKind = (record) => {
    const kindWord = readFirstString(record, KIND_KEYS, 40);
    if (kindWord === undefined)
        return undefined;
    return KIND_ALIASES[kindWord.toLowerCase().replace(/[\s_]/g, "")] ?? "other";
};
/** A recipient string, or a list of them joined by a comma; a "not specified" placeholder is no recipient. */
const readRecipient = (record) => {
    for (const key of RECIPIENT_KEYS) {
        const rawValue = record[key];
        const recipient = Array.isArray(rawValue)
            ? readCappedString(rawValue.filter((entry) => typeof entry === "string").join(", "), MAX_RECIPIENT_LENGTH)
            : readCappedString(rawValue, MAX_RECIPIENT_LENGTH);
        if (recipient !== undefined)
            return NO_RECIPIENT_WORDS.has(recipient.toLowerCase()) ? undefined : recipient;
    }
    return undefined;
};
/** The body as written (outer blank lines trimmed, inner kept); ChatGPT's `content: {format, text}` is read too. */
const readBody = (record) => {
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
const hasUnreadableBodyField = (record) => BODY_KEYS.some((key) => record[key] !== undefined && record[key] !== null && typeof record[key] !== "string");
export const parseWritingDraftPayload = (payload) => {
    let parsedPayload;
    try {
        parsedPayload = JSON.parse(payload ?? "");
    }
    catch {
        return { isValidPayload: false, body: "", isBodyUnreadable: false };
    }
    if (!isRecord(parsedPayload))
        return { isValidPayload: false, body: "", isBodyUnreadable: false };
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
