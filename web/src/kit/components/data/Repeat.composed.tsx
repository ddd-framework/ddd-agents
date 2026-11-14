import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { REPEATABLE_NODE_TYPES_BY_CONTAINER, useDataContainer } from "./data-containers";
import { readFiniteNumber } from "./data-value-format";
import { useReportDataNodeStatus } from "./use-report-data-node-status";

/**
 * The `Repeat` node: its children once per item, with `{"$item":…}` bound to the item. It
 * renders no element of its own, so the repeated nodes sit where it sits — table rows in a `Table`, entries in a
 * `List`, tiles in a `Grid`. Inside a `Table` it repeats only its `TableRow`s, inside a `List` only its `ListItem`s
 * (anything else would be invalid markup there) and reports the rest. The renderer caps the items at `maxRepeatRows`
 * (`renderSlotForItem`) and reports the overflow; `limit` cuts earlier. An empty list reports `empty`.
 */
const RepeatNode = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, runtime, isPending, renderSlotForItem } = props;
  const container = useDataContainer();
  const items = Array.isArray(nodeProps.items) ? nodeProps.items : undefined;
  const limit = readFiniteNumber(nodeProps.limit);
  const shownItems = items && limit !== undefined && limit >= 0 ? items.slice(0, Math.floor(limit)) : (items ?? []);
  const childNodeTypes = (node.nodesBySlotName.children ?? []).map((childNode) => childNode.type);
  const acceptedNodeType = container ? REPEATABLE_NODE_TYPES_BY_CONTAINER[container] : undefined;
  const droppedNodeTypes = acceptedNodeType
    ? childNodeTypes.filter((childNodeType) => childNodeType !== acceptedNodeType)
    : [];
  const hasNoList = !isPending && items === undefined;
  useReportDataNodeStatus(
    runtime,
    isPending
      ? "pending"
      : hasNoList || droppedNodeTypes.length > 0
        ? "failed"
        : shownItems.length === 0
          ? "empty"
          : "mounted",
    hasNoList
      ? "UNRESOLVED_BINDING"
      : droppedNodeTypes.length > 0
        ? "INVALID_NODE"
        : shownItems.length === 0 && !isPending
          ? "NO_DATA"
          : undefined,
    hasNoList
      ? "the Repeat items are not a list"
      : droppedNodeTypes.length > 0
        ? `a Repeat in a ${container} repeats ${acceptedNodeType} nodes only — ${droppedNodeTypes.join(", ")} not shown`
        : shownItems.length === 0 && !isPending
          ? "the Repeat has no items"
          : undefined,
  );
  const repeatedNodes = shownItems.flatMap((item, itemIndex) =>
    renderSlotForItem("children", item, itemIndex).filter(
      (renderedNode, childPosition) =>
        acceptedNodeType === undefined || childNodeTypes[childPosition] === acceptedNodeType,
    ),
  );
  return <>{repeatedNodes}</>;
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Repeat", component: RepeatNode };
