import type { ExpressionValue } from "../../../../renderer/widget-tree/expression/evaluate-expression";
import { readSafeHttpUrl, readSiteHostname, readTextNodeContent } from "./text-node-values";

/** One source of a `SourceRef`, read from its wire object: a name always, the rest only when it can be shown safely. */
export interface SourceRefEntry {
  /** The publisher or site ("WDR", "Wikimedia Commons"). */
  name: string;
  /** The http(s) page; null = the source is shown without a link. */
  url: string | null;
  /** The site of `url`, for its favicon; null = the link glyph. */
  hostname: string | null;
  /** The work's title (an image, an article), for the attribution line. */
  title: string | null;
  author: string | null;
  /** The licence name ("CC BY-SA 4.0"). */
  license: string | null;
  /** The licence's http(s) page; null = the licence is shown as text. */
  licenseUrl: string | null;
}

export interface SourceRefEntries {
  /** The sources that can be shown, at most `maxEntries`. */
  entries: SourceRefEntry[];
  /** Wire items left out because they have no name (or are no object). */
  skippedCount: number;
  /** Items past `maxEntries` (the kit's `maxRepeatRows`). */
  cutCount: number;
  /** Whether `sources` was a list at all. */
  isList: boolean;
}

const readOptionalText = (value: ExpressionValue): string | null => {
  const content = readTextNodeContent(value);
  return content.kind === "text" ? content.text : null;
};

/** The `sources` prop read into entries — never throws: what cannot be shown is counted, the rest is kept in order. */
export const readSourceRefEntries = (sourcesValue: ExpressionValue, maxEntries: number): SourceRefEntries => {
  // Nothing bound yet (a dataset still loading, an unset state) is an empty list, not a wrong one.
  if (sourcesValue === undefined || sourcesValue === null)
    return { entries: [], skippedCount: 0, cutCount: 0, isList: true };
  if (!Array.isArray(sourcesValue)) return { entries: [], skippedCount: 0, cutCount: 0, isList: false };
  const entries: SourceRefEntry[] = [];
  let skippedCount = 0;
  let cutCount = 0;
  for (const sourceValue of sourcesValue) {
    if (typeof sourceValue !== "object" || sourceValue === null || Array.isArray(sourceValue)) {
      skippedCount += 1;
      continue;
    }
    const name = readOptionalText(sourceValue.name);
    if (name === null) {
      skippedCount += 1;
      continue;
    }
    if (entries.length >= maxEntries) {
      cutCount += 1;
      continue;
    }
    const url = readSafeHttpUrl(sourceValue.url);
    entries.push({
      name,
      url,
      hostname: url === null ? null : readSiteHostname(url),
      title: readOptionalText(sourceValue.title),
      author: readOptionalText(sourceValue.author),
      license: readOptionalText(sourceValue.license),
      licenseUrl: readSafeHttpUrl(sourceValue.licenseUrl),
    });
  }
  return { entries, skippedCount, cutCount, isList: true };
};

/** Whether the entry carries an attribution beyond its name (an image licence, an author). */
export const hasAttribution = (entry: SourceRefEntry): boolean => entry.license !== null || entry.author !== null;
