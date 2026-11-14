import { pickText } from "../../_shared/widget-payload-validation";

/** One cited web source — the fields the widget renders (a numbered link with an optional title). */
export interface WebSearchResultSource {
  url: string;
  title?: string;
}

/**
 * Payload carried by `::WebSearchResults{payload='...'}` — the references of a web research answer.
 *
 * DATA-COMPLETE: the widget renders ENTIRELY from this payload — it never re-runs the search or fetches anything.
 * The agent writes its answer as prose and the payload carries only the `sources` it used (url + title,
 * copied from the tool results) plus the `query`. `answer` is the legacy field of messages stored before: when present
 * the widget renders the full answer card.
 *
 * `display` lets the agent force inline vs canvas; omitted → the widget auto-picks (a short answer with few sources
 * renders inline in the chat, a long answer or many sources opens in the canvas side panel).
 */
export interface WebSearchResultsPayload {
  /** The query that was searched (shown as the headline / canvas card title fallback). */
  query: string;
  /** The LLM-written headline for the canvas card (user's language, e.g. "Opening-hours rules in 2026"); falls back to the query. */
  title?: string;
  /** The provider that produced the answer (PERPLEXITY | OPENAI | GEMINI) — shown as a small attribution. */
  provider?: string;
  display?: "inline" | "canvas";
  /** Legacy: the answer as markdown; empty in the references form. */
  answer: string;
  /** The cited sources (url + optional title), in citation order. */
  sources: WebSearchResultSource[];
}

const MAX_SOURCES = 30;
const ANSWER_MAX = 12000;

/**
 * First defined value among the given keys — tolerates the field-name variants a weaker model emits when it
 * reconstructs the payload from the markdown result instead of copying the structured fields (e.g. `link` for `url`,
 * `name` for `title`, `text`/`content` for `answer`). Defensive parsing of LLM output, not a guard.
 */
const pick = (candidate: Record<string, unknown>, ...keys: string[]): unknown => {
  for (const key of keys) {
    if (candidate[key] !== undefined && candidate[key] !== null) return candidate[key];
  }
  return undefined;
};

/** A source's url may arrive directly on the object or nested under a `url_citation` (the raw provider annotation shape). */
const parseSource = (raw: unknown): WebSearchResultSource | null => {
  if (typeof raw !== "object" || raw === null) return null;
  const candidate = raw as Record<string, unknown>;
  const nested = pick(candidate, "url_citation");
  const fields = typeof nested === "object" && nested !== null ? (nested as Record<string, unknown>) : candidate;
  const url = pickText(pick(fields, "url", "link", "uri"), 2000);
  if (!url) return null; // a source with no url is nothing to link
  return {
    url,
    title: pickText(pick(fields, "title", "name"), 300),
  };
};

/**
 * The `sources` may arrive flat (`[{url,title}]`) or wrapped in the serialized EntitySet shape (`{elements:[...]}`)
 * the structured tool result carries — accept both.
 */
const extractRawSources = (candidate: Record<string, unknown>): unknown[] => {
  const sources = pick(candidate, "sources");
  if (Array.isArray(sources)) return sources;
  if (typeof sources === "object" && sources !== null) {
    const elements = (sources as Record<string, unknown>).elements;
    if (Array.isArray(elements)) return elements;
  }
  return [];
};

export const parseWebSearchResultsPayload = (payload: string | undefined): WebSearchResultsPayload => {
  if (!payload) return { query: "", answer: "", sources: [] };
  try {
    const parsed = JSON.parse(payload) as unknown;
    if (typeof parsed !== "object" || parsed === null) return { query: "", answer: "", sources: [] };
    const candidate = parsed as Record<string, unknown>;
    const sources = extractRawSources(candidate)
      .map(parseSource)
      .filter((source): source is WebSearchResultSource => source !== null)
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
  } catch {
    console.warn("[WebSearchResultsConversationWidget] Failed to parse payload JSON", payload);
    return { query: "", answer: "", sources: [] };
  }
};
