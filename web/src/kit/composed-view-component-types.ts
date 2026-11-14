import type React from "react";
import type { ComposedSizeClass } from "../renderer/widget-tree/composed-node-schema";
import type { ComposedViewNode } from "../renderer/widget-tree/composed-view-tree";
import type { ExpressionValue } from "../renderer/widget-tree/expression/evaluate-expression";
import type {
  WidgetRenderReportCode,
  WidgetRenderStatus,
} from "../renderer/render-report/widget-render-report";

/**
 * The contract between the `ComposedView` renderer and ONE kit component. A component is added as
 * one file `components/<group>/<Name>.composed.tsx` that exports `composedViewComponent` — the folder glob of
 * `composed-view-components.ts` registers it; no list is edited. The component receives its node, its props with every
 * binding resolved, its slots already rendered (each slot node inside its own error boundary), the size class of the
 * slot it sits in and the runtime of the tree instance.
 */

export interface ComposedViewRuntime {
  /** The instance state, read-only (inputs write through {@link setStateValue}). */
  state: Record<string, ExpressionValue>;
  /** Write a state value (an input's `onChange: {"set": "<name>"}`); ignored when the tree is frozen (email). */
  setStateValue: (stateName: string, value: unknown) => void;
  /**
   * Run an event prop (`{"set": …}` with a value, or `{"action": …}`). `eventRow` is the row the event fires for when
   * it is not {@link currentRow} (a `Map` point); an `openEntity` action bound to the row (`ref: {"$item":"entityRef"}`)
   * opens that row's `entity`.
   */
  runEvent: (eventWireValue: unknown, eventValue?: unknown, eventRow?: ExpressionValue) => void;
  /**
   * The row of the innermost item scope this node renders in (`$item` of a `Repeat`, a table row), undefined outside
   * one — the row a row-bound `openEntity` action opens the `entity` of.
   */
  currentRow?: ExpressionValue;
  /** The controls may be used (a live message or the newest reply; a replay keeps local inputs usable). */
  interactive: boolean;
  /** The e-mail capture renders the tree: inputs frozen at their initial state, no controls. */
  isEmailRender: boolean;
  /** The host message (canvas anchor) and the key of the agent space the tree is scoped to. */
  messageId?: string | number;
  agentSpaceKey?: string | number;
  /** The tree's named action (an own property of `actions`), or undefined — lets a node tell a resolvable `openEntity` from a dangling name. */
  findAction?: (actionName: string) => Record<string, unknown> | undefined;
  /** Report this node's render status into the block's report. */
  reportNodeStatus: (status: WidgetRenderStatus, code?: WidgetRenderReportCode, detail?: string) => void;
}

export interface ComposedViewComponentProps {
  node: ComposedViewNode;
  /** The node's props, bindings resolved; an unresolved binding is `undefined`. */
  props: Record<string, ExpressionValue>;
  /** Whether a bound prop is still waiting for its data (`$from` before its snapshot, `$source` before hydration) — render a skeleton. */
  isPending: boolean;
  /** The rendered nodes of each slot (`children` = the default slot). A slot of an item-scope node is rendered per row through {@link renderSlotForItem}. */
  slots: Record<string, React.ReactNode[]>;
  /** The room this node has, from the slot it sits in (`full` at the root). */
  sizeClass: ComposedSizeClass;
  /** Renders a slot once per row with `$item` bound to the row — for item-scope nodes (`Repeat`, table bodies). */
  renderSlotForItem: (slotName: string, item: ExpressionValue, itemIndex: number) => React.ReactNode[];
  runtime: ComposedViewRuntime;
  /** QA selector of this node: `<widget test id>-<node type>-<node id or tree path>`; the component derives its parts from it. */
  testId: string;
}

export interface ComposedViewComponentDefinition {
  /** The node type (the backend `NODE_TYPE`). */
  nodeType: string;
  component: React.ComponentType<ComposedViewComponentProps>;
}
