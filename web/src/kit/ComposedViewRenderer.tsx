import { Text } from "../provider/components";
import React, { useCallback, useEffect, useMemo, useReducer, useRef } from "react";
import { stringifyWithinLimit } from "../renderer/widget-tree/bounded-json";
import {
  composedNodeSchema,
  ComposedSizeClass,
  getComposedNodeSchema,
  resolveComposedSlotForNodeProps,
} from "../renderer/widget-tree/composed-node-schema";
import {
  ComposedViewAction,
  ComposedViewLiftedWidget,
  ComposedViewNode,
  ComposedViewTree,
  isComposedViewWithoutContent,
} from "../renderer/widget-tree/composed-view-tree";
import {
  isRowBoundOpenEntityAction,
  readOpenEntityActionTarget,
} from "../renderer/canvas/entity-detail-instance";
import {
  computeComposedViewValues,
  ExpressionValue,
} from "../renderer/widget-tree/expression/evaluate-expression";
import { useComposedViewState } from "../renderer/widget-tree/use-composed-view-state";
import { collectComposedViewSourceBindings } from "../renderer/widget-tree/composed-view-data-sources";
import {
  ComposedViewSourceValue,
  ComposedViewValueScope,
  resolveComposedViewDatasets,
  resolveComposedViewValue,
  ResolvedBindingStatus,
} from "../renderer/widget-tree/resolve-composed-view-values";
import type {
  WidgetRenderReportCode,
  WidgetRenderReportEntry,
  WidgetRenderStatus,
} from "../renderer/render-report/widget-render-report";
import type { ComposedViewRuntime } from "./composed-view-component-types";
import { useComposedViewComponents } from "./composed-view-components";
import { ComposedViewSlotErrorBoundary } from "./ComposedViewSlotErrorBoundary";
import { ComposedViewDataSourceLoader } from "./ComposedViewDataSourceLoader";
import { EmbeddedConversationWidget } from "./components/embedding/EmbeddedConversationWidget";
import { ModelText } from "./ModelText/ModelText";
import { ComposedViewEmailDataTimes } from "../image-render/ComposedViewEmailDataTimes/ComposedViewEmailDataTimes";
import styles from "./ComposedViewConversationWidget.module.scss";

/**
 * Renders a checked `ComposedView` tree: one state store per instance, the datasets and computed
 * values for the current state, then the node tree — each node through its registered component, each slot node in its
 * own error boundary, the slot's size class handed down. The renderer folds the tree's findings, the expression
 * errors, the nodes' own reports and the pending bindings into ONE block status for the render report. A finding that
 * drops nothing (the schema's `noteFindingCodes`: figures without a source, a choice state outside its choices, a set
 * state nothing reads, an `$item` field the rows lack, a slot under its minimum) never fails the block: a block that rendered reports `mounted`
 * with that code as a note, which the chat tells the model.
 */

export interface ComposedViewRendererProps {
  tree: ComposedViewTree;
  interactive: boolean;
  isEmailRender: boolean;
  messageId?: string | number;
  agentSpaceKey?: string | number;
  /** The block status, whenever it changes. */
  onReport: (entry: WidgetRenderReportEntry) => void;
  /**
   * Runs an `openEntity` action — the `entityDetail` canvas. A platform-minted reference carries the entity the backend
   * resolved it to; a row-bound action (`ref: {"$item":"entityRef"}`) comes with `currentRow`, the row it fired in,
   * whose `entity` it opens (`readOpenEntityActionTarget(action, currentRow)`).
   */
  onOpenEntity?: (action: ComposedViewAction, currentRow?: ExpressionValue) => void;
  testId?: string;
}

type NodeReportAction = {
  nodePath: string;
  status: WidgetRenderStatus;
  code?: WidgetRenderReportCode;
  detail?: string;
};
type NodeReports = Record<string, Omit<NodeReportAction, "nodePath">>;

const nodeReportsReducer = (nodeReports: NodeReports, action: NodeReportAction): NodeReports => {
  const current = nodeReports[action.nodePath];
  if (current && current.status === action.status && current.code === action.code && current.detail === action.detail) {
    return nodeReports;
  }
  return { ...nodeReports, [action.nodePath]: { status: action.status, code: action.code, detail: action.detail } };
};

type SourceValueAction = { bindingKey: string; sourceValue: ComposedViewSourceValue };
type SourceValues = Record<string, ComposedViewSourceValue>;

const sourceValuesReducer = (sourceValues: SourceValues, action: SourceValueAction): SourceValues => {
  const current = sourceValues[action.bindingKey];
  if (current && current.status === action.sourceValue.status && current.value === action.sourceValue.value) {
    return sourceValues;
  }
  return { ...sourceValues, [action.bindingKey]: action.sourceValue };
};

const SOURCE_BUDGET_WINDOW_MS = 60_000;

const MAX_DETAIL_FINDINGS = 5;

/**
 * The first node report of `status`, its detail prefixed with the node's path (`view.children[1]: ExampleWidget: …`)
 * unless the detail names it already — the model learns WHICH cell of a dashboard failed or showed nothing.
 */
const findNodeReportWithItsPath = (
  nodeReports: NodeReports,
  status: WidgetRenderStatus,
): WidgetRenderReportEntry | undefined => {
  const nodeReportEntry = Object.entries(nodeReports).find(([, nodeReport]) => nodeReport.status === status);
  if (!nodeReportEntry) return undefined;
  const [nodePath, nodeReport] = nodeReportEntry;
  const detail = nodeReport.detail ?? "";
  return { ...nodeReport, detail: detail.includes(nodePath) ? detail : `${nodePath}: ${detail}`.replace(/: $/, "") };
};

/** The finding codes that drop nothing (`ComposedViewValidationFinding::NOTE_CODES`): the block mounts with a note. */
const NOTE_FINDING_CODES: readonly string[] = composedNodeSchema.noteFindingCodes ?? ["INLINE_VALUE_WITHOUT_SOURCE"];

/**
 * The rendered-node budget of ONE render pass (`maxRenderedNodes`): every node view counts itself; past the budget a
 * node renders nothing and the renderer reports `BUDGET_EXCEEDED` once the pass is committed. A `Repeat` multiplies its
 * template, so this — not the node count of the tree — is what bounds the DOM.
 */
interface RenderedNodeBudget {
  renderedNodeCount: number;
  isExceeded: boolean;
}

interface ComposedViewNodeViewProps {
  node: ComposedViewNode;
  nodePath: string;
  sizeClass: ComposedSizeClass;
  scope: ComposedViewValueScope;
  runtime: ComposedViewRuntime;
  reportNodeStatus: (
    nodePath: string,
    status: WidgetRenderStatus,
    code?: WidgetRenderReportCode,
    detail?: string,
  ) => void;
  /** Reports a node that threw (from its slot boundary). */
  onRenderFailed: (nodePath: string, nodeType: string) => void;
  renderedNodeBudget: RenderedNodeBudget;
  testId: string;
}

const toTestIdSegment = (rawSegment: string) => rawSegment.replace(/[^a-zA-Z0-9]+/g, "-").replace(/^-+|-+$/g, "");

const ComposedViewNodeView = (props: ComposedViewNodeViewProps) => {
  const { node, nodePath, sizeClass, scope, runtime, reportNodeStatus, onRenderFailed, renderedNodeBudget, testId } =
    props;
  const components = useComposedViewComponents();
  const maxRepeatRows = composedNodeSchema.budgets.maxRepeatRows;
  let isOverRepeatRows = false;
  renderedNodeBudget.renderedNodeCount += 1;
  const isOverRenderedNodes = renderedNodeBudget.renderedNodeCount > composedNodeSchema.budgets.maxRenderedNodes;
  if (isOverRenderedNodes) renderedNodeBudget.isExceeded = true;
  const definition = Object.prototype.hasOwnProperty.call(components, node.type) ? components[node.type] : undefined;
  const nodeTypeSchema = getComposedNodeSchema().nodeTypes[node.type];

  useEffect(() => {
    if (!definition)
      reportNodeStatus(nodePath, "failed", "INVALID_NODE", `no component renders the node type ${node.type}`);
  }, [definition, node.type, nodePath, reportNodeStatus]);
  // Runs after the component rendered its items, so it sees whether it asked for more than `maxRepeatRows`.
  useEffect(() => {
    if (isOverRepeatRows) {
      reportNodeStatus(
        nodePath,
        "failed",
        "BUDGET_EXCEEDED",
        `${node.type} shows the first ${maxRepeatRows} rows only`,
      );
    }
  });

  const currentRow = scope.item;
  const nodeRuntime = useMemo<ComposedViewRuntime>(
    () => ({
      ...runtime,
      currentRow,
      // An event of a node in an item scope fires in that scope's row, unless the node names the row itself (a Map point).
      runEvent: (eventWireValue: unknown, eventValue?: unknown, eventRow?: ExpressionValue) =>
        runtime.runEvent(eventWireValue, eventValue, eventRow !== undefined ? eventRow : currentRow),
      reportNodeStatus: (status: WidgetRenderStatus, code?: WidgetRenderReportCode, detail?: string) =>
        reportNodeStatus(nodePath, status, code, detail),
    }),
    [runtime, currentRow, reportNodeStatus, nodePath],
  );

  let isPending = false;
  const markBindingStatus = (status: ResolvedBindingStatus) => {
    if (status === "pending") isPending = true;
  };
  const resolvedProps: Record<string, ExpressionValue> = {};
  for (const [propName, propValue] of Object.entries(node.props)) {
    resolvedProps[propName] = resolveComposedViewValue(propValue, scope, markBindingStatus);
  }

  const renderSlotNodes = (slotName: string, slotScope: ComposedViewValueScope, keySuffix = ""): React.ReactNode[] => {
    // The slot of this node: a Grid's cells narrow with its `columns` (1 full, 2 regular, 3–4 compact).
    const declaredSlot = nodeTypeSchema?.slots.find((slot) => slot.name === slotName);
    const slotSizeClass = (
      declaredSlot ? resolveComposedSlotForNodeProps(declaredSlot, node.props).sizeClass : "regular"
    ) as ComposedSizeClass;
    const slotPathSegment = slotName === "children" ? "children" : `slots.${slotName}`;
    return (node.nodesBySlotName[slotName] ?? []).map((slotNode, slotPosition) => {
      const slotNodePath = `${nodePath}.${slotPathSegment}[${slotPosition}]${keySuffix}`;
      return (
        <ComposedViewSlotErrorBoundary
          key={slotNodePath}
          nodePath={slotNodePath}
          nodeType={slotNode.type}
          onRenderFailed={onRenderFailed}
        >
          <ComposedViewNodeView
            node={slotNode}
            nodePath={slotNodePath}
            sizeClass={slotSizeClass}
            scope={slotScope}
            runtime={runtime}
            reportNodeStatus={reportNodeStatus}
            onRenderFailed={onRenderFailed}
            renderedNodeBudget={renderedNodeBudget}
            testId={testId}
          />
        </ComposedViewSlotErrorBoundary>
      );
    });
  };

  if (!definition || isOverRenderedNodes) return null;
  const providesItemScope = nodeTypeSchema?.providesItemScope ?? false;
  const slots: Record<string, React.ReactNode[]> = {};
  if (!providesItemScope) {
    for (const slotName of Object.keys(node.nodesBySlotName)) slots[slotName] = renderSlotNodes(slotName, scope);
  }
  // A repeating node renders at most `maxRepeatRows` items, whatever its component asks for (enforced here, not per component).
  const renderSlotForItem = (slotName: string, item: ExpressionValue, itemIndex: number) => {
    if (itemIndex >= maxRepeatRows) {
      isOverRepeatRows = true;
      return [];
    }
    return renderSlotNodes(slotName, { ...scope, item }, `#${itemIndex}`);
  };
  const Component = definition.component;
  return (
    <Component
      node={node}
      props={resolvedProps}
      isPending={isPending}
      slots={slots}
      sizeClass={sizeClass}
      renderSlotForItem={renderSlotForItem}
      runtime={nodeRuntime}
      testId={`${testId}-${toTestIdSegment(node.type).toLowerCase()}-${toTestIdSegment(node.id ?? nodePath).toLowerCase()}`}
    />
  );
};

interface ComposedViewLiftedWidgetViewProps {
  liftedWidget: ComposedViewLiftedWidget;
  interactive: boolean;
  messageId?: string | number;
  agentSpaceKey?: string | number;
  reportNodeStatus: ComposedViewNodeViewProps["reportNodeStatus"];
}

/**
 * A widget the reader LIFTED out of the tree: shown after the view as its own widget, at full
 * width, reporting into the block at `lifted <path>`. A stored row lifts nothing — the backend rewrites the row before
 * it is stored — so this renders the trees of rows stored before the lift and of a reply still on its way.
 */
const ComposedViewLiftedWidgetView = (props: ComposedViewLiftedWidgetViewProps) => {
  const { liftedWidget, interactive, messageId, agentSpaceKey, reportNodeStatus } = props;
  const liftedPath = `lifted ${liftedWidget.path}`;
  const reportStatus = useCallback(
    (status: WidgetRenderStatus, code?: WidgetRenderReportCode, detail?: string) =>
      reportNodeStatus(liftedPath, status, code, detail),
    [reportNodeStatus, liftedPath],
  );
  return (
    <EmbeddedConversationWidget
      widgetName={liftedWidget.widget}
      fields={liftedWidget.fields}
      agentSpaceKey={agentSpaceKey}
      interactive={interactive}
      messageId={messageId}
      reportStatus={reportStatus}
    />
  );
};

/** The one muted line of a view left with nothing to show — never a title over an empty Grid. */
const ComposedViewNothingToShow = () => (
  <Text role="help" color="var(--agents-fg-light)">
    This view has nothing to show right now.
  </Text>
);

/** The `ComposedView` tree, rendered. */
export const ComposedViewRenderer = (props: ComposedViewRendererProps) => {
  const {
    tree,
    interactive,
    isEmailRender,
    messageId,
    agentSpaceKey,
    onReport,
    onOpenEntity,
    testId = "composed-view-widget",
  } = props;
  const budgets = composedNodeSchema.budgets;
  const { state, setStateValue, resetState } = useComposedViewState(tree, budgets);
  const [nodeReports, dispatchNodeReport] = useReducer(nodeReportsReducer, {});
  const [sourceValues, dispatchSourceValue] = useReducer(sourceValuesReducer, {});
  const renderedNodeBudget: RenderedNodeBudget = { renderedNodeCount: 0, isExceeded: false };
  useEffect(() => {
    if (renderedNodeBudget.isExceeded) {
      dispatchNodeReport({
        nodePath: "view",
        status: "failed",
        code: "BUDGET_EXCEEDED",
        detail: `more than ${budgets.maxRenderedNodes} rendered nodes — the rest is not shown`,
      });
    }
  });
  const sourceBindings = useMemo(() => collectComposedViewSourceBindings(tree), [tree]);
  const sourceReadTimesRef = useRef<number[]>([]);

  /** The per-tree read budget: every source read after the first one of each binding counts against it. */
  const mayReadSource = useCallback(() => {
    const now = Date.now();
    sourceReadTimesRef.current = sourceReadTimesRef.current.filter(
      (readTime) => now - readTime < SOURCE_BUDGET_WINDOW_MS,
    );
    if (sourceReadTimesRef.current.length >= budgets.maxSourceRequestsPerTreePerMinute) return false;
    sourceReadTimesRef.current.push(now);
    return true;
  }, [budgets.maxSourceRequestsPerTreePerMinute]);
  const onSourceValue = useCallback(
    (bindingKey: string, sourceValue: ComposedViewSourceValue) => dispatchSourceValue({ bindingKey, sourceValue }),
    [],
  );
  const sourceAgentSpaceKey = agentSpaceKey === undefined || agentSpaceKey === "" ? undefined : Number(agentSpaceKey);

  const resolvedDatasets = useMemo(
    () => resolveComposedViewDatasets(tree.data, sourceValues),
    [tree.data, sourceValues],
  );
  const computedValues = useMemo(
    () => computeComposedViewValues(tree.computed, state, resolvedDatasets.datasets, budgets),
    [tree.computed, state, resolvedDatasets.datasets, budgets],
  );

  const writeStateValue = useCallback(
    (stateName: string, value: unknown) => {
      if (!isEmailRender) setStateValue(stateName, value);
    },
    [isEmailRender, setStateValue],
  );

  const runAction = useCallback(
    (actionName: string, eventRow?: ExpressionValue) => {
      const action = Object.prototype.hasOwnProperty.call(tree.actions, actionName)
        ? tree.actions[actionName]
        : undefined;
      if (!action || isEmailRender) return;
      if (action.type === "resetState") resetState();
      if (action.type === "openUrl" && typeof action.url === "string") {
        window.open(action.url, "_blank", "noopener,noreferrer");
      }
      if (action.type === "openEntity" && isRowBoundOpenEntityAction(action)) {
        // The entity is the row's own — never one written on the action; a row without one opens nothing.
        if (readOpenEntityActionTarget(action, eventRow) === undefined) {
          dispatchNodeReport({
            nodePath: `actions.${actionName}`,
            status: "failed",
            code: "UNRESOLVED_REF",
            detail: `the openEntity action "${actionName}" fired in a row without a resolved entity — nothing opened`,
          });
          return;
        }
        onOpenEntity?.(action, eventRow);
      } else if (action.type === "openEntity" && typeof action.ref === "string") onOpenEntity?.(action);
      if (action.type === "copy") {
        const copiedValue = resolveComposedViewValue(action.value, {
          state,
          computed: computedValues.values,
          datasets: resolvedDatasets.datasets,
          datasetStatuses: resolvedDatasets.statusByDatasetName,
          sources: sourceValues,
        });
        // Bounded BEFORE it is built: a value shared many times over can be far larger as text than as a structure.
        const copiedText =
          typeof copiedValue === "string" ? copiedValue : stringifyWithinLimit(copiedValue, budgets.maxSourceBytes);
        if (copiedText === undefined) {
          dispatchNodeReport({
            nodePath: `actions.${actionName}`,
            status: "failed",
            code: "BUDGET_EXCEEDED",
            detail: `the value to copy is larger than ${budgets.maxSourceBytes} characters`,
          });
          return;
        }
        void navigator.clipboard?.writeText(copiedText);
      }
    },
    [
      tree.actions,
      isEmailRender,
      resetState,
      onOpenEntity,
      state,
      computedValues.values,
      resolvedDatasets.datasets,
      resolvedDatasets.statusByDatasetName,
      sourceValues,
      budgets.maxSourceBytes,
    ],
  );

  const reportNodeStatus = useCallback(
    (nodePath: string, status: WidgetRenderStatus, code?: WidgetRenderReportCode, detail?: string) =>
      dispatchNodeReport({ nodePath, status, code, detail }),
    [],
  );
  const onRenderFailed = useCallback(
    (nodePath: string, nodeType: string) =>
      dispatchNodeReport({
        nodePath,
        status: "failed",
        code: "RENDER_FAILED",
        detail: `the ${nodeType} at ${nodePath} threw`,
      }),
    [],
  );

  const runtime = useMemo<ComposedViewRuntime>(
    () => ({
      state,
      setStateValue: writeStateValue,
      runEvent: (eventWireValue: unknown, eventValue?: unknown, eventRow?: ExpressionValue) => {
        if (typeof eventWireValue !== "object" || eventWireValue === null) return;
        const event = eventWireValue as { set?: unknown; action?: unknown };
        if (typeof event.set === "string") writeStateValue(event.set, eventValue);
        if (typeof event.action === "string") runAction(event.action, eventRow);
      },
      interactive,
      isEmailRender,
      messageId,
      agentSpaceKey,
      findAction: (actionName: string) =>
        Object.prototype.hasOwnProperty.call(tree.actions, actionName)
          ? (tree.actions[actionName] as Record<string, unknown>)
          : undefined,
      reportNodeStatus: (status: WidgetRenderStatus, code?: WidgetRenderReportCode, detail?: string) =>
        reportNodeStatus("view", status, code, detail),
    }),
    [
      state,
      writeStateValue,
      runAction,
      interactive,
      isEmailRender,
      messageId,
      agentSpaceKey,
      tree.actions,
      reportNodeStatus,
    ],
  );

  const blockReport = useMemo<WidgetRenderReportEntry>(() => {
    const droppingFindings = tree.findings.filter((finding) => !NOTE_FINDING_CODES.includes(finding.code));
    // a view the lift took entirely leaves its lifted widgets: the block reports their notes, not "no view"
    if (tree.view === null && tree.liftedWidgets.length === 0) {
      return {
        status: "failed",
        code: droppingFindings[0]?.code ?? "INVALID_NODE",
        detail: "the tree has no view to render",
      };
    }
    if (droppingFindings.length > 0) {
      const findingLines = droppingFindings
        .slice(0, MAX_DETAIL_FINDINGS)
        .map((finding) => `${finding.code} at ${finding.path}: ${finding.message}`);
      return {
        status: "failed",
        code: droppingFindings[0].code,
        detail: `${droppingFindings.length} part(s) dropped, the rest rendered — ${findingLines.join("; ")}`,
      };
    }
    const expressionErrors = Object.entries(computedValues.errors);
    if (expressionErrors.length > 0) {
      const [computedName, expressionError] = expressionErrors[0];
      return {
        status: "failed",
        code: expressionError.errorCode,
        detail: `computed "${computedName}": ${expressionError.message}`,
      };
    }
    const failedNodeReport = findNodeReportWithItsPath(nodeReports, "failed");
    if (failedNodeReport) return failedNodeReport;
    const failedDataset = Object.entries(resolvedDatasets.statusByDatasetName).find(
      ([, status]) => status === "failed",
    );
    if (failedDataset) {
      return {
        status: "failed",
        code: "UNRESOLVED_BINDING",
        detail: `the dataset "${failedDataset[0]}" did not resolve`,
      };
    }
    const isPending =
      Object.values(resolvedDatasets.statusByDatasetName).includes("pending") ||
      Object.values(nodeReports).some((nodeReport) => nodeReport.status === "pending");
    if (isPending) return { status: "pending" };
    const emptyNodeReport = findNodeReportWithItsPath(nodeReports, "empty");
    if (emptyNodeReport) return emptyNodeReport;
    // Rendered — but figures without a source are a NOTE the model reads, so its next tree names them.
    const unsourcedFindings = tree.findings.filter((finding) => finding.code === "INLINE_VALUE_WITHOUT_SOURCE");
    if (unsourcedFindings.length > 0) {
      return {
        status: "mounted",
        code: "INLINE_VALUE_WITHOUT_SOURCE",
        detail: `rendered, but ${unsourcedFindings.length} part(s) show figures without a source — ${unsourcedFindings
          .slice(0, MAX_DETAIL_FINDINGS)
          .map((finding) => `${finding.path}: ${finding.message}`)
          .join("; ")}`,
      };
    }
    // The other notes (a choice state outside its choices, a set state nothing reads, an `$item` field the rows lack).
    const noteFindings = tree.findings.filter((finding) => NOTE_FINDING_CODES.includes(finding.code));
    if (noteFindings.length > 0) {
      return {
        status: "mounted",
        code: noteFindings[0].code,
        detail: `rendered, but ${noteFindings.length} part(s) do not work as written — ${noteFindings
          .slice(0, MAX_DETAIL_FINDINGS)
          .map((finding) => `${finding.code} at ${finding.path}: ${finding.message}`)
          .join("; ")}`,
      };
    }
    return { status: "mounted" };
  }, [
    tree.findings,
    tree.view,
    tree.liftedWidgets.length,
    computedValues.errors,
    nodeReports,
    resolvedDatasets.statusByDatasetName,
  ]);

  useEffect(() => {
    onReport(blockReport);
  }, [blockReport, onReport]);

  const sourceLoaders = sourceBindings.map((sourceBinding) => (
    <ComposedViewDataSourceLoader
      key={sourceBinding.bindingKey}
      sourceBinding={sourceBinding}
      state={state}
      agentSpaceKey={sourceAgentSpaceKey}
      mayRead={mayReadSource}
      onValue={onSourceValue}
    />
  ));
  const liftedWidgetViews = tree.liftedWidgets.map((liftedWidget) => (
    <ComposedViewLiftedWidgetView
      key={liftedWidget.path}
      liftedWidget={liftedWidget}
      interactive={interactive}
      messageId={messageId}
      agentSpaceKey={agentSpaceKey}
      reportNodeStatus={reportNodeStatus}
    />
  ));
  if (tree.view === null) {
    // the lift took the whole view: only its widgets stand; nothing at all: one explicit line, never a blank block
    return (
      <div className={styles.root}>
        {sourceLoaders}
        {liftedWidgetViews.length > 0 ? liftedWidgetViews : <ComposedViewNothingToShow />}
      </div>
    );
  }
  // a layout that LOST all its content to the reader (a dropped or lifted node) shows the explicit line, never an
  // empty Grid; a layout written empty on purpose renders and reports `empty` itself
  const lostAllItsContent =
    isComposedViewWithoutContent(tree.view) &&
    tree.findings.some((finding) => !NOTE_FINDING_CODES.includes(finding.code) || finding.code === "WIDGET_LIFTED");
  const rootScope: ComposedViewValueScope = {
    state,
    computed: computedValues.values,
    datasets: resolvedDatasets.datasets,
    datasetStatuses: resolvedDatasets.statusByDatasetName,
    sources: sourceValues,
  };
  return (
    <div className={styles.root} data-testid={testId}>
      {sourceLoaders}
      {tree.title ? (
        <ModelText role="intro" weight="bold">
          {tree.title}
        </ModelText>
      ) : null}
      {lostAllItsContent ? (
        <ComposedViewNothingToShow />
      ) : (
        <ComposedViewSlotErrorBoundary nodePath="view" nodeType={tree.view.type} onRenderFailed={onRenderFailed}>
          <ComposedViewNodeView
            node={tree.view}
            nodePath="view"
            sizeClass="full"
            scope={rootScope}
            runtime={runtime}
            reportNodeStatus={reportNodeStatus}
            onRenderFailed={onRenderFailed}
            renderedNodeBudget={renderedNodeBudget}
            testId={testId}
          />
        </ComposedViewSlotErrorBoundary>
      )}
      {liftedWidgetViews}
      {/* The e-mail states when each data path was read: turn-time `$from` vs mail-time `$source`. */}
      {isEmailRender ? <ComposedViewEmailDataTimes tree={tree} /> : null}
    </div>
  );
};
