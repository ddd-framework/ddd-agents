/**
 * The `Clock` payload (DATA-COMPLETE: zone ids and labels only, the time is computed in the browser). The parser is
 * alias-tolerant (weak models rename fields while they write: `tz_name`, `city`, `zones`, …), caps every list and every
 * string, and never throws — malformed JSON gives an empty payload, which the widget reports as `empty`.
 */

/** At most this many clocks render; the rest are dropped silently, as every list cap in the widgets. */
export const MAX_CLOCKS = 8;

const MAX_TITLE_LENGTH = 160;
const MAX_LABEL_LENGTH = 120;
const MAX_ABBREVIATION_LENGTH = 12;
const MAX_TIME_ZONE_LENGTH = 64;

export type ClockTimeFormat = "24h" | "12h";

/** One clock as the model wrote it — the time zone not yet checked against the browser's zone data. */
export interface ClockPayloadEntry {
  /** The IANA time zone id exactly as written (trimmed): "Europe/Berlin". */
  timeZone: string;
  /** The place in the owner's language; undefined → derived from the zone id. */
  label?: string;
  /** The zone abbreviation the model passed ("CET"); undefined → the browser's short zone name. */
  abbreviation?: string;
}

export interface ClockPayload {
  title?: string;
  timeFormat?: ClockTimeFormat;
  clocks: ClockPayloadEntry[];
}

const CLOCK_LIST_KEYS = ["clocks", "zones", "timeZones", "timezones", "items"] as const;
const TIME_ZONE_KEYS = ["timeZone", "timezone", "tz", "tz_name", "tzName", "zone", "ianaTimeZone"] as const;
const LABEL_KEYS = ["label", "city", "location", "name", "place"] as const;
const ABBREVIATION_KEYS = ["abbreviation", "tz_alias", "tzAlias", "alias"] as const;
const TIME_FORMAT_KEYS = ["timeFormat", "time_format", "hourCycle"] as const;

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

/** `24h` / `12h`, also written `24` / `12` / `h23` / `h12`; anything else → undefined (the owner settings decide). */
const readTimeFormat = (record: Record<string, unknown>): ClockTimeFormat | undefined => {
  for (const key of TIME_FORMAT_KEYS) {
    const rawValue = record[key];
    const normalizedValue =
      typeof rawValue === "number"
        ? String(rawValue)
        : typeof rawValue === "string"
          ? rawValue.trim().toLowerCase()
          : "";
    if (["24h", "24", "h23", "h24"].includes(normalizedValue)) return "24h";
    if (["12h", "12", "h12", "h11", "ampm", "am/pm"].includes(normalizedValue)) return "12h";
  }
  return undefined;
};

/** One entry: a plain zone id string, or an object with the zone under one of its aliases (or `parameters.tz_name`). */
const readClockEntry = (rawEntry: unknown): ClockPayloadEntry | undefined => {
  if (typeof rawEntry === "string") {
    const timeZone = readCappedString(rawEntry, MAX_TIME_ZONE_LENGTH);
    return timeZone ? { timeZone } : undefined;
  }
  if (!isRecord(rawEntry)) return undefined;
  // ChatGPT's own shape nests the fields under `parameters` — accepted, the model may copy it.
  const entryRecord = isRecord(rawEntry.parameters) ? { ...rawEntry.parameters, ...rawEntry } : rawEntry;
  const timeZone = readFirstString(entryRecord, TIME_ZONE_KEYS, MAX_TIME_ZONE_LENGTH);
  if (!timeZone) return undefined;
  const label = readFirstString(entryRecord, LABEL_KEYS, MAX_LABEL_LENGTH);
  const abbreviation = readFirstString(entryRecord, ABBREVIATION_KEYS, MAX_ABBREVIATION_LENGTH);
  return { timeZone, ...(label ? { label } : {}), ...(abbreviation ? { abbreviation } : {}) };
};

const readClockList = (payloadRecord: Record<string, unknown>): unknown[] => {
  for (const key of CLOCK_LIST_KEYS) {
    if (Array.isArray(payloadRecord[key])) return payloadRecord[key] as unknown[];
  }
  // One clock written at the top level: `{"timeZone": "Asia/Tokyo", "label": "Tokio"}`.
  return readFirstString(payloadRecord, TIME_ZONE_KEYS, MAX_TIME_ZONE_LENGTH) ? [payloadRecord] : [];
};

export const parseClockPayload = (payload: string | undefined): ClockPayload => {
  let parsedPayload: unknown;
  try {
    parsedPayload = JSON.parse(payload ?? "");
  } catch {
    return { clocks: [] };
  }
  if (!isRecord(parsedPayload)) return { clocks: [] };
  const clocks = readClockList(parsedPayload)
    .slice(0, MAX_CLOCKS)
    .map(readClockEntry)
    .filter((clockEntry): clockEntry is ClockPayloadEntry => clockEntry !== undefined);
  const title = readCappedString(parsedPayload.title, MAX_TITLE_LENGTH);
  const timeFormat = readTimeFormat(parsedPayload);
  return { clocks, ...(title ? { title } : {}), ...(timeFormat ? { timeFormat } : {}) };
};
