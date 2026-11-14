import { CUSTOM_VISUALIZATION_LIBRARY_NAMES } from "./custom-visualization-libraries";
import {
  CUSTOM_VISUALIZATION_MAX_HEIGHT_PX,
  CUSTOM_VISUALIZATION_MIN_HEIGHT_PX,
} from "./custom-visualization-frame-protocol";

/**
 * The payload of a CustomVisualization block: the model's own HTML, CSS and JavaScript plus the data the
 * script draws, run in a sandboxed frame. DATA-COMPLETE: the block carries everything; the frame fetches nothing.
 * Mirrors the backend class `CustomVisualizationConversationWidget` (fields, the source cap and its measure).
 */
export interface CustomVisualizationPayload {
  title: string;
  description: string;
  html: string;
  css: string;
  script: string;
  /** Names on the allow-list (`custom-visualization-libraries.ts`), loaded before the script. */
  libraries: string[];
  /** Any JSON the script reads as `window.AGENTS_VISUALIZATION.data`; null when the block has none. */
  data: unknown;
  /** The frame's height before the frame reports its own (CSS px, clamped). */
  initialHeight: number;
}

export type CustomVisualizationPayloadProblem =
  | { code: "NO_CONTENT"; detail: string }
  | { code: "SOURCE_TOO_LARGE"; detail: string }
  | { code: "UNKNOWN_LIBRARY"; detail: string };

export type ParsedCustomVisualizationPayload =
  | { isValid: true; payload: CustomVisualizationPayload; sourceBytes: number }
  | { isValid: false; problem: CustomVisualizationPayloadProblem; title: string };

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

const readFirstString = (fields: Record<string, unknown>, keys: readonly string[]): string => {
  for (const key of keys) {
    const value = fields[key];
    if (typeof value === "string") return value;
  }
  return "";
};

const readLibraryNames = (fields: Record<string, unknown>): string[] => {
  const librariesField = fields.libraries ?? fields.libs;
  const libraries = typeof librariesField === "string" ? [librariesField] : librariesField;
  if (!Array.isArray(libraries)) return [];
  return libraries
    .filter((libraryName): libraryName is string => typeof libraryName === "string")
    .map((libraryName) => libraryName.trim().toLowerCase())
    .filter(
      (libraryName, libraryIndex, libraryNames) =>
        libraryName !== "" && libraryNames.indexOf(libraryName) === libraryIndex,
    )
    .slice(0, MAX_LIBRARIES);
};

const readInitialHeight = (fields: Record<string, unknown>): number => {
  const height = fields.height ?? fields.initialHeight;
  const heightNumber = typeof height === "string" ? Number(height) : height;
  if (typeof heightNumber !== "number" || !Number.isFinite(heightNumber)) return CUSTOM_VISUALIZATION_DEFAULT_HEIGHT_PX;
  return Math.min(
    CUSTOM_VISUALIZATION_MAX_HEIGHT_PX,
    Math.max(CUSTOM_VISUALIZATION_MIN_HEIGHT_PX, Math.round(heightNumber)),
  );
};

const encodeUtf8Length = (text: string): number => new TextEncoder().encode(text).length;

/**
 * The bytes the source cap counts: `html`, `css`, `script` and the compact JSON of `data` (nothing for no data), as
 * UTF-8 — the backend's `measureSourceBytes()` counts the same.
 */
export const measureCustomVisualizationSourceBytes = (
  payload: Pick<CustomVisualizationPayload, "html" | "css" | "script" | "data">,
): number => {
  let dataJson = "";
  if (payload.data !== null && payload.data !== undefined) {
    try {
      dataJson = JSON.stringify(payload.data) ?? "";
    } catch {
      dataJson = "";
    }
  }
  return (
    encodeUtf8Length(payload.html) +
    encodeUtf8Length(payload.css) +
    encodeUtf8Length(payload.script) +
    encodeUtf8Length(dataJson)
  );
};

/**
 * Reads a block's payload. Alias-tolerant (`markup` / `styles` / `js` / `code`, `subtitle` / `caption`, `libs`,
 * `initialHeight`), never throws: a malformed payload, a block without HTML and script, a source above the cap or a
 * library outside the allow-list comes back invalid with the problem the widget reports.
 */
export const parseCustomVisualizationPayload = (rawPayload: string | undefined): ParsedCustomVisualizationPayload => {
  let fields: Record<string, unknown> = {};
  try {
    const parsedPayload: unknown = rawPayload ? JSON.parse(rawPayload) : {};
    if (typeof parsedPayload === "object" && parsedPayload !== null && !Array.isArray(parsedPayload)) {
      fields = parsedPayload as Record<string, unknown>;
    }
  } catch {
    fields = {};
  }

  const title = readFirstString(fields, ["title", "name"]).trim().slice(0, MAX_TITLE_CHARS);
  const payload: CustomVisualizationPayload = {
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
  const unknownLibraryName = payload.libraries.find(
    (libraryName) => !CUSTOM_VISUALIZATION_LIBRARY_NAMES.includes(libraryName),
  );
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
