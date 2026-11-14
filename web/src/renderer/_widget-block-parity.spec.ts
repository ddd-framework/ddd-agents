import { describe, expect, it } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkGfm from "remark-gfm";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import type { LeafDirective } from "mdast-util-directive";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import conversationWidgetAstPlugin from "./conversationWidgetAstPlugin";
import restoreTextDirectivesPlugin from "./restoreTextDirectivesPlugin";
import { normalizeServerMessageToMarkdown } from "../chat/normalize-server-message-to-markdown";
import widgetBlockParityFixture from "./widget-block-parity.json";

/**
 * Parity between the backend's ONE widget-block parser
 * (`App\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks`, asserted by the PHPUnit
 * `WidgetBlocksParityTest`) and the frontend pipeline a STATIC (already-delivered, non-streaming) chat bubble runs —
 * the exact steps `ConversationMessageList`/`ConversationMessageWidgets` apply before handing markdown to
 * `ConversationMarkdown`:
 *
 *   1. `normalizeServerMessageToMarkdown` (calls `repairMalformedWidgetFences` then `referenceNakedWidgetJson`)
 *   2. the exact `remarkPlugins` array `Markdown.tsx` builds, in order: `Markdown.tsx:102` PREPENDS `remarkGfm` to
 *      whatever `ConversationMarkdown.tsx` passes in (`computedRemarkPlugins = [remarkGfm, ...remarkPlugins]`), so
 *      the real order is `remarkGfm` FIRST, then `ConversationMarkdown.tsx`'s own list: `remarkDirective`,
 *      `remarkWidgetFencePlugin`, `conversationWidgetAstPlugin`, `restoreTextDirectivesPlugin` (the last one touches
 *      only `textDirective` nodes, never a widget's `leafDirective`, but is included so the chain matches exactly).
 *
 * `conversationWidgetAstPlugin` is the step that resolves a node's name onto the CANONICAL registry name (stripping
 * a legacy trailing `Directive` once) and drops an unknown name to plain text. The resulting ordered list is what
 * name registered widget component the registry/`ConversationWidgetHost` would MOUNT by `hName` — it is NOT a claim
 * about what the customer visibly sees: `ConversationWidgetHost`'s feature gate can render an upsell instead of the
 * widget, its error boundary can swallow a throwing widget, and `WindowedWidget` can replace an off-screen widget
 * with a placeholder. Two other paths are deliberately NOT represented here and read neither `repairMalformedWidgetFences`
 * nor `referenceNakedWidgetJson`: the STREAMING bubble (`ConversationMessageWidgets.tsx:82-83` only strips the
 * `{preCallMessage}` sentinel via `stripPreCallMessageMarker`, with no repair and no naked-JSON referencing, while a
 * message is still arriving token-by-token) and `widgetNamesInMarkdown` (`markdown-contains-widget.ts`, a separate
 * regex-based scan the message list uses to detect a superseded widget — it never runs this remark pipeline at all).
 *
 * The fixture's `backend`/`divergence` fields are read, never written by this spec.
 */

interface BackendWidgetBlock {
  syntax: string;
  outcome: string;
  isClosed: boolean;
  writtenWidgetType: string | null;
  /** The origin the block's `emittedBy` names; null = no field, i.e. the model. */
  emittedBy: string | null;
}

interface WidgetBlockParityCase {
  name: string;
  markdown: string;
  backend: BackendWidgetBlock[];
  frontendRenderedWidgetNames: string[] | null;
  divergence: string | null;
}

const isBackendWidgetBlock = (value: unknown): value is BackendWidgetBlock => {
  if (typeof value !== "object" || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return (
    typeof candidate.syntax === "string" &&
    typeof candidate.outcome === "string" &&
    typeof candidate.isClosed === "boolean" &&
    (typeof candidate.writtenWidgetType === "string" || candidate.writtenWidgetType === null) &&
    (typeof candidate.emittedBy === "string" || candidate.emittedBy === null)
  );
};

/** Runtime guard so the fixture's shape is verified rather than blindly cast onto `WidgetBlockParityCase`. */
const isWidgetBlockParityCase = (value: unknown): value is WidgetBlockParityCase => {
  if (typeof value !== "object" || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return (
    typeof candidate.name === "string" &&
    typeof candidate.markdown === "string" &&
    Array.isArray(candidate.backend) &&
    candidate.backend.every(isBackendWidgetBlock) &&
    (candidate.frontendRenderedWidgetNames === null ||
      (Array.isArray(candidate.frontendRenderedWidgetNames) &&
        candidate.frontendRenderedWidgetNames.every((name) => typeof name === "string"))) &&
    (candidate.divergence === null || typeof candidate.divergence === "string")
  );
};

const cases: WidgetBlockParityCase[] = (widgetBlockParityFixture.cases as unknown[]).map((candidate) => {
  if (!isWidgetBlockParityCase(candidate)) {
    throw new Error(`widget-block-parity.json: malformed fixture case ${JSON.stringify(candidate)}`);
  }
  return candidate;
});

/** The rendered widget nodes of a static chat bubble's exact remark pipeline. */
const renderedWidgetNodes = (rawMessage: string): LeafDirective[] => {
  const markdown = normalizeServerMessageToMarkdown(rawMessage);
  const processor = unified()
    .use(remarkParse)
    .use(remarkGfm)
    .use(remarkDirective)
    .use(remarkWidgetFencePlugin)
    .use(conversationWidgetAstPlugin)
    .use(restoreTextDirectivesPlugin);
  const tree = processor.runSync(processor.parse(markdown));

  const nodes: LeafDirective[] = [];
  visit(tree, "leafDirective", (node: LeafDirective) => {
    // `conversationWidgetAstPlugin` rewrites every rendered widget node's `data.hName` to the canonical registry
    // name — the same key `ConversationMarkdown`'s component map and `ConversationWidgetHost` mount by. A dropped
    // (unknown) widget has no `data.hName` (it was replaced with a plain text node), so it is never collected.
    if (hNameOf(node)) {
      nodes.push(node);
    }
  });
  return nodes;
};

const hNameOf = (node: LeafDirective): string | undefined => (node.data as { hName?: string } | undefined)?.hName;

/** The exact remark pipeline + rendered-widget-name extraction a static chat bubble runs. */
const renderedWidgetNames = (rawMessage: string): string[] =>
  renderedWidgetNodes(rawMessage).map((node) => hNameOf(node) ?? "");

describe("widget-block-parity fixture — frontend pipeline", () => {
  it.each(cases.map((testCase) => [testCase.name, testCase] as const))("%s", (_name, testCase) => {
    expect(testCase.frontendRenderedWidgetNames).not.toBeNull();
    expect(renderedWidgetNames(testCase.markdown)).toEqual(testCase.frontendRenderedWidgetNames);
  });
});

/**
 * A block the backend writer stores carries `emittedBy` as its last field. The frontend tolerates
 * it with no code change — the fence plugin moves every non-`type` field into the `payload` string, so the field rides
 * along inside the payload (every widget's parser picks the fields it knows) and never becomes a prop of its own: the
 * node's attributes, which `conversationWidgetAstPlugin` hands to the component as props, stay `payload` (+ the agent space key field the app declares).
 */
describe("widget-block-parity fixture — the emittedBy origin field", () => {
  const emittedByCases = cases.filter((testCase) => testCase.backend.some((block) => block.emittedBy !== null));

  it("lists at least one block with an origin", () => {
    expect(emittedByCases.length).toBeGreaterThan(0);
  });

  it.each(emittedByCases.map((testCase) => [testCase.name, testCase] as const))(
    "%s: the field stays inside the payload, never a prop",
    (_name, testCase) => {
      const [node] = renderedWidgetNodes(testCase.markdown);
      expect(Object.keys(node.attributes ?? {})).toEqual(["payload"]);
      const payload = JSON.parse(String(node.attributes?.payload)) as Record<string, unknown>;
      expect(payload.emittedBy).toBe(testCase.backend[0].emittedBy);
      const payloadKeys = Object.keys(payload);
      expect(payloadKeys[payloadKeys.length - 1]).toBe("emittedBy");

      const withoutOrigin = testCase.markdown.replace(/,"emittedBy":"[^"]*"/, "");
      expect(withoutOrigin).not.toContain("emittedBy");
      expect(renderedWidgetNames(withoutOrigin)).toEqual(renderedWidgetNames(testCase.markdown));
    },
  );
});
