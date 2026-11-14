import { findBalancedJsonObjectEnd } from "./balanced-json-object";
import { parseWidgetJsonObject } from "./widget-fence-json";
import { BARE_WIDGET_LINE_GLOBAL, WIDGET_FENCE_LANG } from "./widget-fence-syntax";
import { closestWidgetNames, resolveWidgetName } from "./widget-name-resolver";

/**
 * The signal provenance for the widget-render-failure corrective signal — distinct from a widget CLICK. Mirrors the
 * backend {@link AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_INVALID}; the admin inspector shows it as the
 * signal source and the agent loop treats it as model-visible feedback.
 */
export const WIDGET_INVALID_SIGNAL_SOURCE = "FRONTEND_WIDGET_INVALID";

export interface UnrenderedWidget {
  /** The `type` the model wrote. */
  type: string;
  /**
   * What went wrong:
   * - `unknownType`: a widget block (fenced or bare `widget { … }` line) whose type is not a registered widget →
   *   dropped, the customer sees NOTHING.
   * - `missingFence`: widget JSON emitted as PLAIN TEXT (no ```widget fence, no `widget` prefix) → the customer sees
   *   raw JSON prose.
   */
  kind: "unknownType" | "missingFence";
  /** The closest valid widget names (deterministic similarity) for a "did you mean" — may be empty. */
  suggestions: string[];
}

// A ```widget fenced block: ```widget <optional trailing> \n <json> \n ``` . Non-greedy body; the `m`-less dotall
// via [\s\S] so a multi-line JSON body is captured. Built from the shared lang constant so "``` widget" (a space
// before the lang — remark accepts it) is recognized the same way as the render plugin and the repairer.
const WIDGET_FENCE = new RegExp("```[ \\t]*" + WIDGET_FENCE_LANG + "[^\\n]*\\n([\\s\\S]*?)```", "g");

// ANY fenced block — stripped before the naked-JSON scan so fenced content (handled widgets, legit code samples)
// is never re-reported as fence-less.
const ANY_FENCE = /```[\s\S]*?```/g;

/**
 * Parse a candidate JSON snippet into its `type` string, or null when it isn't an object with a string `type` — read
 * like the render plugin reads a block (`parseWidgetJsonObject`: a surplus trailing `}` / `]` is tolerated).
 */
const parseWidgetType = (jsonCandidate: string): string | null => {
  const type = parseWidgetJsonObject(jsonCandidate)?.type;
  return typeof type === "string" && type !== "" ? type : null;
};

/**
 * Extract balanced `{…}` JSON candidates that start at a line beginning (after whitespace) in PLAIN text — the shape
 * a model emits when it writes a widget payload without the fence (observed live: the final answer carried
 * `{ "type": "ExampleWidget", … }` as its own paragraph). A simple brace-balance walk (strings respected) keeps this
 * dependency-free; nested objects are fine. Bare `widget { … }` lines are NOT captured here (they start with the
 * `widget` prefix, not `{`) — remarkWidgetFencePlugin renders those, and the bare-line scan below covers their
 * unknown types.
 */
const extractNakedJsonCandidates = (plainText: string): string[] => {
  const candidates: string[] = [];
  const lines = plainText.split("\n");
  let offset = 0;
  for (const line of lines) {
    const lineStart = offset;
    offset += line.length + 1;
    if (!line.trimStart().startsWith("{")) continue;
    const openIndex = lineStart + line.indexOf("{");
    const jsonObjectEnd = findBalancedJsonObjectEnd(plainText, openIndex);
    if (jsonObjectEnd !== null) {
      candidates.push(plainText.slice(openIndex, jsonObjectEnd + 1));
    }
  }
  return candidates;
};

/**
 * Find widget-emission failures in an assistant message — the cases where the customer does NOT get the intended
 * widget. Uses the SAME resolveWidgetName as the render plugin, so "valid" means exactly "would have rendered".
 * Three scan surfaces, two failure classes:
 *
 * 1. `unknownType` — a ```widget fence OR a bare `widget { … }` line (both forms remarkWidgetFencePlugin renders)
 *    whose `type` is not a registered widget: the plugin silently DROPS it, the customer sees nothing and the model
 *    gets no feedback (a model wrote `type: "example"` instead of `ExampleWidget`). A malformed-JSON
 *    block is skipped (the plugin drops it too).
 * 2. `missingFence` — widget JSON emitted as PLAIN prose (no fence, no `widget` prefix): nothing intercepts it, so
 *    the customer sees raw JSON text (found live: `{ "type": "ExampleWidget", … }` as a paragraph of the final answer).
 *    Reported when the naked JSON's `type` resolves to a VALID widget (the model clearly meant a widget) or is CLOSE
 *    to one (similarity shortlist non-empty) — arbitrary JSON without a widget-like `type` is never flagged (no false
 *    positives on legitimate JSON content).
 */
export const detectUnrenderedWidgets = (messageText: string): UnrenderedWidget[] => {
  const unrendered: UnrenderedWidget[] = [];
  const seenTypes = new Set<string>();
  const report = (type: string, kind: UnrenderedWidget["kind"], suggestions: string[]) => {
    if (seenTypes.has(type)) return;
    seenTypes.add(type);
    unrendered.push({ type, kind, suggestions });
  };

  // 1) Rendered-form blocks (```widget fence + bare `widget { … }` line) with an unknown type.
  if (messageText.includes("widget")) {
    for (const renderedFormRegex of [WIDGET_FENCE, BARE_WIDGET_LINE_GLOBAL]) {
      renderedFormRegex.lastIndex = 0;
      let match: RegExpExecArray | null;
      while ((match = renderedFormRegex.exec(messageText)) !== null) {
        const type = parseWidgetType(match[1]);
        if (type === null) continue;
        if (resolveWidgetName(type)) continue; // resolves → it rendered; nothing to report
        report(type, "unknownType", closestWidgetNames(type));
      }
    }
  }

  // 2) Naked widget JSON outside any fence.
  const plainText = messageText.replace(ANY_FENCE, "");
  if (plainText.includes('"type"')) {
    for (const jsonCandidate of extractNakedJsonCandidates(plainText)) {
      const type = parseWidgetType(jsonCandidate);
      if (type === null) continue;
      if (resolveWidgetName(type)) {
        report(type, "missingFence", []);
        continue;
      }
      const suggestions = closestWidgetNames(type);
      if (suggestions.length > 0) {
        report(type, "unknownType", suggestions);
      }
      // No resolution AND nothing close → arbitrary JSON, not a widget attempt — never flagged.
    }
  }

  return unrendered;
};

/**
 * The user-INVISIBLE corrective SIGNAL text for one or more widget-emission failures — tells the model exactly what
 * the customer experienced (nothing rendered / raw JSON prose) and how to fix it. Kept terse; the model already holds
 * the full widget catalog in its instructions.
 */
export const buildUnrenderedWidgetSignal = (widgets: UnrenderedWidget[]): string => {
  const parts = widgets.map((widget) => {
    if (widget.kind === "missingFence") {
      return `You emitted the "${widget.type}" widget as PLAIN JSON text — without the \`\`\`widget code fence it does not render and the customer sees raw JSON prose.`;
    }
    const suggestion =
      widget.suggestions.length > 0 ? ` Closest valid widget names: ${widget.suggestions.join(", ")}.` : "";
    return `"${widget.type}" is not a valid widget type, so it did not render and the customer sees nothing.${suggestion}`;
  });
  return (
    `A widget you emitted did not reach the customer. ${parts.join(" ")} ` +
    "Re-emit it as a ```widget code block (fence on its own lines) using the EXACT valid widget name that matches your intent."
  );
};
