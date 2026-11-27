import { normalizeSearchHitExcerpt } from "./search-hit-excerpt";
import { pickText } from "../../_shared/widget-payload-validation";
const MAX_GROUPS = 20;
const MAX_HITS_PER_GROUP = 6;
const EXCERPT_MAX = 600;
const toFiniteInt = (value) => typeof value === "number" && Number.isFinite(value) ? Math.trunc(value) : undefined;
/** First defined value among the given keys — tolerates the field-name variants weaker models emit when they
 *  reconstruct the payload from the markdown result instead of copying the structured `groups` (e.g. `conversation_id`
 *  for `conversationId`, `messages` for `hits`, `content` for `excerpt`). Defensive parsing of LLM output, not a guard. */
const pick = (candidate, ...keys) => {
    for (const key of keys) {
        if (candidate[key] !== undefined && candidate[key] !== null)
            return candidate[key];
    }
    return undefined;
};
const parseHit = (raw) => {
    if (typeof raw !== "object" || raw === null)
        return null;
    const candidate = raw;
    const excerpt = pickText(normalizeSearchHitExcerpt(pick(candidate, "excerpt", "content", "text", "message")), EXCERPT_MAX);
    if (!excerpt)
        return null; // a hit with no text is nothing to show
    return {
        role: pickText(pick(candidate, "role"), 32),
        excerpt,
        indexInConversation: toFiniteInt(pick(candidate, "indexInConversation", "index")),
        messageId: toFiniteInt(pick(candidate, "messageId", "id")),
        // The message's "when" is its created time; `dateTime`/`date` kept as back-compat aliases, `updated` as a last resort.
        created: pickText(pick(candidate, "created", "dateTime", "date", "updated"), 32),
        updated: pickText(pick(candidate, "updated"), 32),
        dateTime: pickText(pick(candidate, "dateTime", "date", "created", "updated"), 32),
    };
};
const parseGroup = (raw) => {
    if (typeof raw !== "object" || raw === null)
        return null;
    const candidate = raw;
    const rawHits = pick(candidate, "hits", "messages");
    const hits = Array.isArray(rawHits)
        ? rawHits
            .map(parseHit)
            .filter((hit) => hit !== null)
            .slice(0, MAX_HITS_PER_GROUP)
        : [];
    if (hits.length === 0)
        return null; // a group with no shown hits is dropped
    return {
        conversationId: toFiniteInt(pick(candidate, "conversationId", "conversation_id")),
        title: pickText(pick(candidate, "title"), 200),
        summary: pickText(pick(candidate, "summary"), 600),
        // The group's display date is the conversation's most-recent activity (`updated`), falling back to its created time;
        // `dateTime`/`date` kept as back-compat aliases for older copied payloads.
        created: pickText(pick(candidate, "created", "dateTime", "date"), 32),
        updated: pickText(pick(candidate, "updated"), 32),
        dateTime: pickText(pick(candidate, "dateTime", "date", "updated", "created"), 32),
        hits,
    };
};
export const parseConversationSearchResultsPayload = (payload) => {
    if (!payload)
        return { query: "", groups: [] };
    try {
        const parsed = JSON.parse(payload);
        if (typeof parsed !== "object" || parsed === null)
            return { query: "", groups: [] };
        const candidate = parsed;
        const groups = Array.isArray(candidate.groups)
            ? candidate.groups
                .map(parseGroup)
                .filter((group) => group !== null)
                .slice(0, MAX_GROUPS)
            : [];
        const display = candidate.display === "inline" || candidate.display === "canvas" ? candidate.display : undefined;
        return {
            query: pickText(candidate.query, 200) ?? "",
            title: pickText(candidate.title, 120),
            display,
            groups,
        };
    }
    catch {
        console.warn("[ConversationSearchResultsConversationWidget] Failed to parse payload JSON", payload);
        return { query: "", groups: [] };
    }
};
