const parseJsonString = (value) => {
    try {
        return JSON.parse(value);
    }
    catch {
        return undefined;
    }
};
const readTextFromParsedPayload = (parsed) => {
    if (typeof parsed === "string") {
        return parsed;
    }
    if (typeof parsed !== "object" || parsed === null) {
        return undefined;
    }
    const payload = parsed;
    if (typeof payload.text === "string") {
        return payload.text;
    }
    if (typeof payload.content === "string") {
        return payload.content;
    }
    if (Array.isArray(payload.content)) {
        const textParts = payload.content
            .map((part) => (typeof part.text === "string" ? part.text : ""))
            .filter((text) => text.trim().length > 0);
        return textParts.length > 0 ? textParts.join("\n\n") : undefined;
    }
    return undefined;
};
const unescapeJsonString = (value) => {
    const parsed = parseJsonString(`"${value}"`);
    return typeof parsed === "string" ? parsed : value;
};
const readTextFromJsonExcerpt = (excerpt) => {
    const matches = Array.from(excerpt.matchAll(/"text"\s*:\s*"((?:\\.|[^"\\])*)"/g));
    if (matches.length === 0) {
        return undefined;
    }
    return matches
        .map((match) => unescapeJsonString(match[1] ?? ""))
        .filter((text) => text.trim().length > 0)
        .join("\n\n");
};
export const normalizeSearchHitExcerpt = (rawExcerpt) => {
    if (typeof rawExcerpt !== "string") {
        const parsedText = readTextFromParsedPayload(rawExcerpt);
        return parsedText ?? "";
    }
    const trimmedExcerpt = rawExcerpt.trim();
    const parsedText = readTextFromParsedPayload(parseJsonString(trimmedExcerpt));
    if (parsedText !== undefined) {
        return parsedText;
    }
    return readTextFromJsonExcerpt(trimmedExcerpt) ?? rawExcerpt;
};
