import { conversationWidgetRegistry, getConversationWidgetRegistryRevision } from "./conversationWidgetRegistry";

/**
 * WIRE LAYER — this file owns the ONE rule that maps a name off the wire onto a registered widget.
 *
 * CANONICAL names are SHORT: `QuickReplies`, `MultiChoice`, `WebSearchResults`. That is what the registry holds, what the
 * agent emits in a ```widget fence `type`, and what every doc and FE-generated message writes today.
 *
 * LEGACY names carry a trailing `Directive`: stored conversation messages in the database contain
 * `::QuickRepliesDirective{payload='…'}` and ```widget fences with `"type": "QuickRepliesDirective"`. Those rows are
 * permanent — the database is not rewritten.
 *
 * THE RULE (in `resolveCanonicalWidgetName`, and nowhere else):
 *   1. exact match against the canonical names;
 *   2. ONLY if the requested name ends in `Directive` — strip that suffix ONCE and retry the exact match;
 *   3. otherwise undefined (unknown widget).
 *
 * Consequence: the `Directive` suffix is RESERVED as the legacy marker. Never register a new widget under a name
 * ending in `Directive` — `_registry-wire-names.spec.ts` asserts no canonical name does, and asserts that every legacy
 * long name still resolves to the canonical one, so old conversations keep rendering forever.
 */

/** The suffix every pre-canonicalization wire name carried. Reserved as the legacy marker — see the file header. */
const LEGACY_NAME_SUFFIX = "Directive";

let widgetNameSet: ReadonlySet<string> | undefined;
let widgetNameSetRevision = -1;

/**
 * Every registered widget name, canonical (short) — the runtime source of truth for what renders. Read LAZILY: the
 * registry imports every widget, and a widget can import AgentChat, which imports this file — a module-scope read
 * would run before the registry finished initializing whenever a widget is the first module loaded (a story). Rebuilt
 * when an app registered more widgets since (`registerConversationWidgets`).
 */
const getWidgetNameSet = (): ReadonlySet<string> => {
  if (widgetNameSet === undefined || widgetNameSetRevision !== getConversationWidgetRegistryRevision()) {
    widgetNameSet = new Set(conversationWidgetRegistry.widgets.map((widget) => widget.name));
    widgetNameSetRevision = getConversationWidgetRegistryRevision();
  }
  return widgetNameSet;
};

/**
 * THE resolution rule (see the file header): exact canonical match, else — only for a name ending in `Directive` —
 * strip that legacy suffix once and match again. Undefined = no such widget. Every lookup in the pipeline goes through
 * this function (the fence plugin, the AST plugin, the drawer-reset scan, the unrendered-widget detector), so a name
 * that renders in one place renders in all of them.
 */
export const resolveCanonicalWidgetName = (
  requestedName: string,
  canonicalNames: ReadonlySet<string>,
): string | undefined => {
  if (canonicalNames.has(requestedName)) return requestedName;
  if (!requestedName.endsWith(LEGACY_NAME_SUFFIX)) return undefined;
  const withoutLegacySuffix = requestedName.slice(0, -LEGACY_NAME_SUFFIX.length);
  return canonicalNames.has(withoutLegacySuffix) ? withoutLegacySuffix : undefined;
};

/**
 * Resolve a model-supplied ```widget `type` — or the name on a stored message's `::<Name>` node — to a registered
 * widget name. `QuickReplies` matches exactly; the legacy `QuickRepliesDirective` resolves to the same canonical
 * `QuickReplies`. Shared by the render plugin (which DROPS an unresolved block) and the unrendered-widget detector
 * (which REPORTS it), so both agree exactly on what counts as a valid widget.
 */
export const resolveWidgetName = (type: string): string | undefined =>
  resolveCanonicalWidgetName(type, getWidgetNameSet());

/** Normalize a widget name / type for fuzzy comparison: lowercase, drop a trailing `Directive`. */
const normalizeWidgetName = (value: string): string => value.toLowerCase().replace(/directive$/, "");

/** Split a PascalCase / camelCase widget name into lowercase word tokens (`WebSearchResults` → [web, search, results]). */
const wordTokens = (value: string): string[] =>
  value
    .replace(/directive$/i, "")
    .split(/(?=[A-Z])/)
    .map((token) => token.toLowerCase())
    .filter(Boolean);

/** Levenshtein edit distance — the fine tiebreaker for the closest-widget shortlist (dependency-free). */
const editDistance = (a: string, b: string): number => {
  const rows = a.length + 1;
  const cols = b.length + 1;
  const dist: number[][] = Array.from({ length: rows }, () => new Array<number>(cols).fill(0));
  for (let i = 0; i < rows; i++) dist[i][0] = i;
  for (let j = 0; j < cols; j++) dist[0][j] = j;
  for (let i = 1; i < rows; i++) {
    for (let j = 1; j < cols; j++) {
      dist[i][j] = Math.min(
        dist[i - 1][j] + 1,
        dist[i][j - 1] + 1,
        dist[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1),
      );
    }
  }
  return dist[rows - 1][cols - 1];
};

/**
 * The closest VALID widget names to an unknown `type` the model emitted — a deterministic similarity shortlist (no
 * LLM): substring containment + shared PascalCase tokens + edit-distance proximity as the tiebreaker. A candidate is
 * kept only when it genuinely resembles the type (containment, a shared word, or a very close spelling) — proximity
 * alone is too loose across ~60 widget names. Returned as the canonical (short) names, which is what a corrected
 * emission should use; empty when nothing is close.
 */
export const closestWidgetNames = (type: string, limit = 3): string[] => {
  const target = normalizeWidgetName(type);
  if (target === "") return [];
  const targetTokens = new Set(wordTokens(type));
  return [...getWidgetNameSet()]
    .map((name) => {
      const candidate = normalizeWidgetName(name);
      const candidateTokens = wordTokens(name);
      const contains = candidate.includes(target) || target.includes(candidate) ? 3 : 0;
      const overlap = candidateTokens.filter((token) => targetTokens.has(token)).length;
      // Proximity against the WHOLE name and against each word token (so a single-word typo like `conections` still
      // matches the `connections` token of a multi-word widget), best of the two.
      const nameProximity = 1 - editDistance(target, candidate) / Math.max(target.length, candidate.length);
      const tokenProximity = Math.max(
        0,
        ...candidateTokens.map((token) => 1 - editDistance(target, token) / Math.max(target.length, token.length)),
      );
      const proximity = Math.max(nameProximity, tokenProximity);
      return {
        name,
        keep: contains > 0 || overlap > 0 || proximity >= 0.6,
        score: contains + overlap + proximity,
      };
    })
    .filter((entry) => entry.keep)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit)
    .map((entry) => entry.name);
};
