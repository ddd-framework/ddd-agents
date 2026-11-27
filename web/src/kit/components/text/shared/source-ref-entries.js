import { readSafeHttpUrl, readSiteHostname, readTextNodeContent } from "./text-node-values";
const readOptionalText = (value) => {
    const content = readTextNodeContent(value);
    return content.kind === "text" ? content.text : null;
};
/** The `sources` prop read into entries — never throws: what cannot be shown is counted, the rest is kept in order. */
export const readSourceRefEntries = (sourcesValue, maxEntries) => {
    // Nothing bound yet (a dataset still loading, an unset state) is an empty list, not a wrong one.
    if (sourcesValue === undefined || sourcesValue === null)
        return { entries: [], skippedCount: 0, cutCount: 0, isList: true };
    if (!Array.isArray(sourcesValue))
        return { entries: [], skippedCount: 0, cutCount: 0, isList: false };
    const entries = [];
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
export const hasAttribution = (entry) => entry.license !== null || entry.author !== null;
