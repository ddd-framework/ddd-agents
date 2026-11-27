/**
 * The file card widget `DriveFiles` (`DriveFilesConversationWidget` of the backend): the block the agent writes with
 * `showDriveFiles` names a drive store and file references only (`drive/812`, at most 20) plus an optional headline.
 * The renderer reads the cards through the host's `DriveFileCardsSource` under the viewer's rights, so the block never
 * carries a URL and a viewer without the right sees no file. Pure (no React), so the logic tests cover it.
 */
/** The wire name of the widget (`DriveFilesConversationWidget::WIDGET_TYPE`). */
export const DRIVE_FILES_WIDGET_TYPE = "DriveFiles";
/** The most files of one card (`DriveFilesConversationWidget::MAX_FILES`). */
export const DRIVE_FILES_MAX_FILES = 20;
/** The longest headline the block may carry. */
const DRIVE_FILES_TITLE_MAX_LENGTH = 160;
const isRecord = (value) => typeof value === "object" && value !== null && !Array.isArray(value);
/** A positive integer key from a markdown attribute (string) or a block field (number); `null` otherwise. */
const toSelfHydrationKey = (value) => {
    const key = typeof value === "string" && value.trim() !== "" ? Number(value) : value;
    return typeof key === "number" && Number.isInteger(key) && key > 0 ? key : null;
};
const readPayloadObject = (payload) => {
    if (typeof payload !== "string" || payload.trim() === "")
        return null;
    try {
        const parsed = JSON.parse(payload);
        return isRecord(parsed) ? parsed : null;
    }
    catch {
        return null;
    }
};
const readReferences = (value) => {
    if (!Array.isArray(value))
        return [];
    const references = [];
    for (const candidate of value) {
        if (typeof candidate !== "string")
            continue;
        const reference = candidate.trim();
        if (reference === "" || references.includes(reference))
            continue;
        references.push(reference);
        if (references.length === DRIVE_FILES_MAX_FILES)
            break;
    }
    return references;
};
/**
 * The request of one `DriveFiles` block, or `null` when it names no store or no reference. The self-hydration key is
 * the block's attribute (`selfHydrationKeyAttribute`), else the payload's field of that name.
 */
export const parseDriveFilesPayload = (payload, selfHydrationKeyFieldName, selfHydrationKeyAttribute) => {
    const fields = readPayloadObject(payload);
    if (fields === null)
        return null;
    const store = typeof fields.store === "string" ? fields.store.trim() : "";
    const references = readReferences(fields.references);
    if (store === "" || references.length === 0)
        return null;
    const title = typeof fields.title === "string" ? fields.title.trim().slice(0, DRIVE_FILES_TITLE_MAX_LENGTH) : "";
    return {
        store,
        references,
        title: title === "" ? null : title,
        widgetSelfHydrationKey: toSelfHydrationKey(selfHydrationKeyAttribute ?? fields[selfHydrationKeyFieldName]),
    };
};
/** One hydration memory per block content: the store, the space and the references in their order. */
export const buildDriveFilesHydrationKey = (type, request) => `${type}:${request.store}:${request.widgetSelfHydrationKey ?? ""}:${request.references.join(",")}`;
/** The node id inside a reference (`drive/812` → `812`), or `null` for a reference of another shape. */
export const nodeIdOfReference = (reference) => {
    const match = /^[\w-]+\/([\w-]+)$/.exec(reference.trim());
    return match?.[1] ?? null;
};
/**
 * The card's own link into the host's drive screen, when it is safe to follow inside the app: a path of this origin
 * (`/admin/drive?…`, never protocol-relative `//…`) or an absolute http(s) URL of `origin`. `null` otherwise.
 */
export const safeDriveFileOpenLink = (link, origin) => {
    const trimmed = (link ?? "").trim();
    if (trimmed === "")
        return null;
    if (trimmed.startsWith("/"))
        return trimmed.startsWith("//") || trimmed.includes("\\") ? null : trimmed;
    if (origin === null)
        return null;
    try {
        const url = new URL(trimmed);
        return (url.protocol === "https:" || url.protocol === "http:") && url.origin === origin ? trimmed : null;
    }
    catch {
        return null;
    }
};
/** Milliseconds of an offsetless UTC `Y-m-d H:i:s` (or ISO) date-time, `null` for anything else. */
export const parseDriveDateTimeMs = (value) => {
    const trimmed = (value ?? "").trim();
    if (trimmed === "")
        return null;
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(trimmed);
    if (match) {
        const [, year, month, day, hour, minute, second] = match;
        return Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second ?? 0));
    }
    const parsed = Date.parse(trimmed);
    return Number.isNaN(parsed) ? null : parsed;
};
/** How long before `urlsExpireAt` a URL already counts as expired: the click, the redirect and the bucket's clock. */
const URL_EXPIRY_MARGIN_MS = 30000;
/**
 * Whether the card's URLs (open, download, thumbnail) are expired at `nowMs`: `urlsExpireAt` is past or within
 * {@link URL_EXPIRY_MARGIN_MS}. A card without an expiry carries no URL to trust and counts as expired.
 */
export const isDriveFileCardExpired = (card, nowMs) => {
    const expiresAtMs = parseDriveDateTimeMs(card.urlsExpireAt);
    return expiresAtMs === null || expiresAtMs - URL_EXPIRY_MARGIN_MS <= nowMs;
};
const extensionOfName = (name) => {
    const dot = name.lastIndexOf(".");
    return dot > 0 && dot < name.length - 1 ? name.slice(dot + 1).toLowerCase() : "";
};
const FAMILY_BY_EXTENSION = {
    pdf: "PDF",
    xls: "SPREADSHEET",
    xlsx: "SPREADSHEET",
    ods: "SPREADSHEET",
    csv: "SPREADSHEET",
    doc: "DOCUMENT",
    docx: "DOCUMENT",
    odt: "DOCUMENT",
    rtf: "DOCUMENT",
    ppt: "PRESENTATION",
    pptx: "PRESENTATION",
    odp: "PRESENTATION",
    key: "PRESENTATION",
    svg: "VECTOR",
    ai: "VECTOR",
    eps: "VECTOR",
    zip: "ARCHIVE",
    gz: "ARCHIVE",
    tar: "ARCHIVE",
    "7z": "ARCHIVE",
    rar: "ARCHIVE",
};
/** The file family of a card (its icon and colour): by the content type, else by the extension; text otherwise. */
export const driveFileFamilyOf = (file) => {
    const contentType = (file.contentType ?? "").toLowerCase();
    if (contentType === "image/svg+xml")
        return "VECTOR";
    if (contentType.startsWith("image/"))
        return "IMAGE";
    if (contentType.startsWith("video/"))
        return "VIDEO";
    if (contentType === "application/pdf")
        return "PDF";
    const byExtension = FAMILY_BY_EXTENSION[extensionOfName(file.name ?? "")];
    if (byExtension)
        return byExtension;
    if (contentType.includes("spreadsheet") || contentType.includes("excel"))
        return "SPREADSHEET";
    if (contentType.includes("presentation") || contentType.includes("powerpoint"))
        return "PRESENTATION";
    if (contentType.includes("wordprocessing") || contentType.includes("msword"))
        return "DOCUMENT";
    if (contentType.includes("zip") || contentType.includes("compressed"))
        return "ARCHIVE";
    return "TEXT";
};
/** The type a card names: the extension in capitals (`XLSX`), or `null` when the name has none. */
export const driveFileTypeLabelOf = (file) => {
    const extension = extensionOfName(file.name ?? "");
    return extension === "" ? null : extension.toUpperCase();
};
/** Families a browser tab shows itself from the inline URL; an office file or an archive would only download there. */
const BROWSER_VIEWABLE_FAMILIES = ["PDF", "IMAGE", "VECTOR", "VIDEO", "TEXT"];
/** The path of a card without its extension (`out/deck.pptx` → `out/deck`), the name when the card has no path. */
const stemOfCard = (file) => {
    const path = file.path ?? file.name ?? "";
    const extension = extensionOfName(path);
    return extension === "" ? path : path.slice(0, -(extension.length + 1));
};
/**
 * The card "open" shows in a new tab: the card itself when the browser can show it; for an office file (pptx, docx,
 * xlsx …) its PDF twin among the block's cards (same folder, same name, `.pdf`: an agent hands a deck over as pptx and
 * pdf); `null` when there is none, then the card only downloads (no blank tab that turns into a download).
 */
export const driveFileCardToOpen = (card, cards) => {
    if (card.isFolder)
        return null;
    if (BROWSER_VIEWABLE_FAMILIES.includes(driveFileFamilyOf(card)))
        return card;
    const stem = stemOfCard(card);
    return (cards.find((other) => other !== card && !other.isFolder && driveFileFamilyOf(other) === "PDF" && stemOfCard(other) === stem) ?? null);
};
/** A file size for people: bytes, KB, MB, GB in the locale's number format, one decimal from KB on (no trailing `,0`). */
export const formatDriveFileSize = (sizeBytes, locale) => {
    const units = ["B", "KB", "MB", "GB", "TB"];
    let value = sizeBytes;
    let unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }
    const formatted = new Intl.NumberFormat(locale ?? undefined, {
        maximumFractionDigits: unitIndex === 0 ? 0 : 1,
    }).format(value);
    return `${formatted} ${units[unitIndex]}`;
};
