import { pickText } from "../../_shared/widget-payload-validation";
const MAX_SOURCES = 30;
const ANSWER_MAX = 12000;
/**
 * First defined value among the given keys — tolerates the field-name variants a weaker model emits when it
 * reconstructs the payload from the markdown result instead of copying the structured fields (e.g. `link` for `url`,
 * `name` for `title`, `text`/`content` for `answer`). Defensive parsing of LLM output, not a guard.
 */
const pick = (candidate, ...keys) => {
    for (const key of keys) {
        if (candidate[key] !== undefined && candidate[key] !== null)
            return candidate[key];
    }
    return undefined;
};
/** A source's url may arrive directly on the object or nested under a `url_citation` (the raw provider annotation shape). */
const parseSource = (raw) => {
    if (typeof raw !== "object" || raw === null)
        return null;
    const candidate = raw;
    const nested = pick(candidate, "url_citation");
    const fields = typeof nested === "object" && nested !== null ? nested : candidate;
    const url = pickText(pick(fields, "url", "link", "uri"), 2000);
    if (!url)
        return null; // a source with no url is nothing to link
    return {
        url,
        title: pickText(pick(fields, "title", "name"), 300),
    };
};
/**
 * The `sources` may arrive flat (`[{url,title}]`) or wrapped in the serialized EntitySet shape (`{elements:[...]}`)
 * the structured tool result carries — accept both.
 */
const extractRawSources = (candidate) => {
    const sources = pick(candidate, "sources");
    if (Array.isArray(sources))
        return sources;
    if (typeof sources === "object" && sources !== null) {
        const elements = sources.elements;
        if (Array.isArray(elements))
            return elements;
    }
    return [];
};
export const parseWebSearchResultsPayload = (payload) => {
    if (!payload)
        return { query: "", answer: "", sources: [] };
    try {
        const parsed = JSON.parse(payload);
        if (typeof parsed !== "object" || parsed === null)
            return { query: "", answer: "", sources: [] };
        const candidate = parsed;
        const sources = extractRawSources(candidate)
            .map(parseSource)
            .filter((source) => source !== null)
            .slice(0, MAX_SOURCES);
        const display = candidate.display === "inline" || candidate.display === "canvas" ? candidate.display : undefined;
        return {
            query: pickText(pick(candidate, "query"), 300) ?? "",
            title: pickText(pick(candidate, "title"), 160),
            provider: pickText(pick(candidate, "provider"), 40),
            display,
            answer: pickText(pick(candidate, "answer", "text", "content"), ANSWER_MAX) ?? "",
            sources,
        };
    }
    catch {
        console.warn("[WebSearchResultsConversationWidget] Failed to parse payload JSON", payload);
        return { query: "", answer: "", sources: [] };
    }
};
