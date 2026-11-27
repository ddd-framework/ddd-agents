import { CUSTOM_VISUALIZATION_LIBRARY_NAMES } from "./custom-visualization-libraries";
import { CUSTOM_VISUALIZATION_MAX_HEIGHT_PX, CUSTOM_VISUALIZATION_MIN_HEIGHT_PX, } from "./custom-visualization-frame-protocol";
/**
 * The most source a block may carry: the UTF-8 bytes of `html` + `css` + `script` + the JSON of `data`. Backend twin:
 * `CustomVisualizationConversationWidget::MAX_SOURCE_BYTES`.
 */
export const CUSTOM_VISUALIZATION_MAX_SOURCE_BYTES = 65536;
/** The frame's height when the block names none. */
export const CUSTOM_VISUALIZATION_DEFAULT_HEIGHT_PX = 360;
const MAX_TITLE_CHARS = 160;
const MAX_DESCRIPTION_CHARS = 400;
const MAX_LIBRARIES = 4;
const readFirstString = (fields, keys) => {
    for (const key of keys) {
        const value = fields[key];
        if (typeof value === "string")
            return value;
    }
    return "";
};
const readLibraryNames = (fields) => {
    const librariesField = fields.libraries ?? fields.libs;
    const libraries = typeof librariesField === "string" ? [librariesField] : librariesField;
    if (!Array.isArray(libraries))
        return [];
    return libraries
        .filter((libraryName) => typeof libraryName === "string")
        .map((libraryName) => libraryName.trim().toLowerCase())
        .filter((libraryName, libraryIndex, libraryNames) => libraryName !== "" && libraryNames.indexOf(libraryName) === libraryIndex)
        .slice(0, MAX_LIBRARIES);
};
const readInitialHeight = (fields) => {
    const height = fields.height ?? fields.initialHeight;
    const heightNumber = typeof height === "string" ? Number(height) : height;
    if (typeof heightNumber !== "number" || !Number.isFinite(heightNumber))
        return CUSTOM_VISUALIZATION_DEFAULT_HEIGHT_PX;
    return Math.min(CUSTOM_VISUALIZATION_MAX_HEIGHT_PX, Math.max(CUSTOM_VISUALIZATION_MIN_HEIGHT_PX, Math.round(heightNumber)));
};
const encodeUtf8Length = (text) => new TextEncoder().encode(text).length;
/**
 * The bytes the source cap counts: `html`, `css`, `script` and the compact JSON of `data` (nothing for no data), as
 * UTF-8 — the backend's `measureSourceBytes()` counts the same.
 */
export const measureCustomVisualizationSourceBytes = (payload) => {
    let dataJson = "";
    if (payload.data !== null && payload.data !== undefined) {
        try {
            dataJson = JSON.stringify(payload.data) ?? "";
        }
        catch {
            dataJson = "";
        }
    }
    return (encodeUtf8Length(payload.html) +
        encodeUtf8Length(payload.css) +
        encodeUtf8Length(payload.script) +
        encodeUtf8Length(dataJson));
};
/**
 * Reads a block's payload. Alias-tolerant (`markup` / `styles` / `js` / `code`, `subtitle` / `caption`, `libs`,
 * `initialHeight`), never throws: a malformed payload, a block without HTML and script, a source above the cap or a
 * library outside the allow-list comes back invalid with the problem the widget reports.
 */
export const parseCustomVisualizationPayload = (rawPayload) => {
    let fields = {};
    try {
        const parsedPayload = rawPayload ? JSON.parse(rawPayload) : {};
        if (typeof parsedPayload === "object" && parsedPayload !== null && !Array.isArray(parsedPayload)) {
            fields = parsedPayload;
        }
    }
    catch {
        fields = {};
    }
    const title = readFirstString(fields, ["title", "name"]).trim().slice(0, MAX_TITLE_CHARS);
    const payload = {
        title,
        description: readFirstString(fields, ["description", "subtitle", "caption"]).trim().slice(0, MAX_DESCRIPTION_CHARS),
        html: readFirstString(fields, ["html", "markup"]),
        css: readFirstString(fields, ["css", "styles", "style"]),
        script: readFirstString(fields, ["script", "js", "javascript", "code"]),
        libraries: readLibraryNames(fields),
        data: fields.data ?? null,
        initialHeight: readInitialHeight(fields),
    };
    if (payload.html.trim() === "" && payload.script.trim() === "") {
        return {
            isValid: false,
            title,
            problem: { code: "NO_CONTENT", detail: "The CustomVisualization block carries neither html nor script." },
        };
    }
    const unknownLibraryName = payload.libraries.find((libraryName) => !CUSTOM_VISUALIZATION_LIBRARY_NAMES.includes(libraryName));
    if (unknownLibraryName !== undefined) {
        return {
            isValid: false,
            title,
            problem: {
                code: "UNKNOWN_LIBRARY",
                detail: `The library "${unknownLibraryName.slice(0, 40)}" is not available; allowed: ${CUSTOM_VISUALIZATION_LIBRARY_NAMES.join(", ")}.`,
            },
        };
    }
    const sourceBytes = measureCustomVisualizationSourceBytes(payload);
    if (sourceBytes > CUSTOM_VISUALIZATION_MAX_SOURCE_BYTES) {
        return {
            isValid: false,
            title,
            problem: {
                code: "SOURCE_TOO_LARGE",
                detail: `The CustomVisualization source has ${sourceBytes} bytes; at most ${CUSTOM_VISUALIZATION_MAX_SOURCE_BYTES} are allowed.`,
            },
        };
    }
    return { isValid: true, payload, sourceBytes };
};
