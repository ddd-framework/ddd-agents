import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import remarkWidgetFencePlugin from "../remarkWidgetFencePlugin";
import { resolveWidgetName } from "../widget-name-resolver";
import { readWidgetAgentSpaceKeyAttribute } from "../_agent-space/widget-agent-space-key-attribute";
import { shortStringHash } from "./canvas-state";
import { getComposedViewSubtreeNodes, parseComposedViewTree } from "../widget-tree/composed-view-tree";

/** The wire name of the composable widget kit — its tree may embed domain widgets as `Widget` nodes. */
const COMPOSED_VIEW_WIDGET_NAME = "ComposedView";

/** One widget the anchor message carries — what a canvas body parses its own payload from. */
export interface WidgetFenceInMessage {
  /** The CANONICAL wire name (a stored legacy `<Name>Directive` resolves onto it). */
  readonly name: string;
  /** The fence's non-`type` fields as the JSON string every widget parser takes (`payload`). */
  readonly payload: string;
  /** The agent space key the framework lifts out of the fence (the block field the app declares), when present. */
  readonly agentSpaceKey?: string;
  /** `shortStringHash(payload)` — what `canvasInstanceIdForMessagePayload` puts into the instance id. */
  readonly payloadHash: string;
  /** Every string attribute of the node (`target`, `mode`, … — widgets that do not use `payload`). */
  readonly attributes: Readonly<Record<string, string>>;
}

/**
 * Every widget in a message's markdown, in document order, through the SAME pipeline the message renders with
 * (`remarkDirective` for the stored `::Name{payload='…'}` form, `remarkWidgetFencePlugin` for the ```widget fence and
 * the bare `widget { … }` line) — so a canvas body re-reads exactly the payload its inline widget rendered from, in
 * both the canonical and the legacy wire form. Malformed or unknown widgets are dropped, as when rendering.
 *
 * A `ComposedView` block is followed by the domain widgets its tree embeds (`Widget` nodes), each as if
 * its own fence stood there — `payload` = the node's `fields` — so a canvas opened from a widget in a slot finds its
 * payload like any other canvas body, and the reload restore finds the widget that opens the canvas.
 *
 * Shell-side only (the pipeline imports the widget registry through the name resolver): imported by canvas bodies,
 * never by the slice or the canvas model modules.
 */
export const findWidgetFencesInMarkdown = (markdown: string): WidgetFenceInMessage[] => {
  if (!markdown) return [];
  const processor = unified().use(remarkParse).use(remarkDirective).use(remarkWidgetFencePlugin);
  const tree = processor.runSync(processor.parse(markdown));
  const fences: WidgetFenceInMessage[] = [];
  visit(tree, "leafDirective", (node: any) => {
    const name = typeof node.name === "string" ? resolveWidgetName(node.name) : undefined;
    if (!name) return;
    const attributes = (node.attributes ?? {}) as Record<string, unknown>;
    const payload = typeof attributes.payload === "string" ? attributes.payload : "{}";
    const stringAttributes = Object.fromEntries(
      Object.entries(attributes).filter((entry): entry is [string, string] => typeof entry[1] === "string"),
    );
    const agentSpaceKey = readWidgetAgentSpaceKeyAttribute(attributes);
    fences.push({
      name,
      payload,
      ...(typeof agentSpaceKey === "string" ? { agentSpaceKey } : {}),
      payloadHash: shortStringHash(payload),
      attributes: stringAttributes,
    });
    if (name === COMPOSED_VIEW_WIDGET_NAME) fences.push(...findWidgetsEmbeddedInComposedView(payload));
  });
  return fences;
};

/**
 * The domain widgets a `ComposedView` payload embeds (its KEPT `Widget` nodes — only embeddable ones survive the
 * reader), in tree order, as fences. Total: a payload the reader cannot take gives none, it never throws (the canvas
 * restore and the anchor lookups call this outside any error boundary).
 */
const findWidgetsEmbeddedInComposedView = (composedViewPayload: string): WidgetFenceInMessage[] => {
  try {
    return readWidgetsEmbeddedInComposedView(composedViewPayload);
  } catch {
    return [];
  }
};

const readWidgetsEmbeddedInComposedView = (composedViewPayload: string): WidgetFenceInMessage[] => {
  const tree = parseComposedViewTree(composedViewPayload);
  if (tree.view === null) return [];
  const embeddedFences: WidgetFenceInMessage[] = [];
  for (const node of getComposedViewSubtreeNodes(tree.view)) {
    if (node.type !== "Widget" || typeof node.props.widget !== "string") continue;
    const name = resolveWidgetName(node.props.widget);
    if (!name) continue;
    const fields = typeof node.props.fields === "object" && node.props.fields !== null ? node.props.fields : {};
    const payload = JSON.stringify(fields);
    const agentSpaceKey = readWidgetAgentSpaceKeyAttribute(fields);
    embeddedFences.push({
      name,
      payload,
      ...(agentSpaceKey !== undefined ? { agentSpaceKey: String(agentSpaceKey) } : {}),
      payloadHash: shortStringHash(payload),
      attributes: { payload },
    });
  }
  return embeddedFences;
};

/**
 * The fence a canvas is anchored on: the widget of `name` whose payload hash the canvas instance id carries
 * (`canvasInstanceIdForMessagePayload`). Two identical widgets in one message share one canvas by design.
 */
export const findWidgetFenceByPayloadHash = (
  markdown: string,
  name: string,
  payloadHash: string,
): WidgetFenceInMessage | undefined =>
  findWidgetFencesInMarkdown(markdown).find((fence) => fence.name === name && fence.payloadHash === payloadHash);
