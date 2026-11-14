import { classNames } from "../../../provider/class-names";
import { useMemo } from "react";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { DataContainerContext, ListContext, ListContextValue } from "./data-containers";
import { readObject, readText } from "./data-value-format";
import { ListEntry } from "./ListEntry/ListEntry";
import { DataEmptyLine } from "./DataEmptyLine/DataEmptyLine";
import { useReportDataNodeStatus } from "./use-report-data-node-status";
import styles from "./List.module.scss";

const MARKERS: ReadonlyArray<ListContextValue["marker"]> = ["none", "bullet", "number", "check"];

interface ShortFormListEntry {
  title?: string;
  description?: string;
  meta?: string;
  value?: string;
}

/** One entry of the short form: a string is the title; an object gives title / description / meta / value. */
const readShortFormEntry = (itemValue: ExpressionValue): ShortFormListEntry | undefined => {
  const itemText = readText(itemValue);
  if (itemText !== undefined) return { title: itemText };
  const itemObject = readObject(itemValue);
  if (!itemObject) return undefined;
  const entry: ShortFormListEntry = {
    title: readText(itemObject.title) ?? readText(itemObject.label) ?? readText(itemObject.name),
    description: readText(itemObject.description) ?? readText(itemObject.text),
    meta: readText(itemObject.meta),
    value: readText(itemObject.value),
  };
  return entry.title || entry.description ? entry : undefined;
};

/**
 * The `List` node: entries divided by hairlines, an `<ol>` when
 * the marker is `number`, a `<ul>` otherwise. The short form `items` renders before the node form's `ListItem` /
 * `Repeat` children. The short form is capped at `maxRepeatRows` (reported); no entry at all shows one muted line and
 * reports `empty`.
 */
const ListNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, runtime, isPending, testId } = props;
  const maxRepeatRows = composedNodeSchema.budgets.maxRepeatRows;
  const marker = MARKERS.find((knownMarker) => knownMarker === nodeProps.marker) ?? "none";
  const listContextValue = useMemo<ListContextValue>(() => ({ marker }), [marker]);
  const shortFormEntries = (Array.isArray(nodeProps.items) ? nodeProps.items : [])
    .map(readShortFormEntry)
    .filter((entry): entry is ShortFormListEntry => entry !== undefined);
  const shownEntries = shortFormEntries.slice(0, maxRepeatRows);
  const childEntries = slots.children ?? [];
  const isEmpty = !isPending && shortFormEntries.length === 0 && childEntries.length === 0;
  const isOverEntryBudget = shortFormEntries.length > maxRepeatRows;
  useReportDataNodeStatus(
    runtime,
    isPending ? "pending" : isOverEntryBudget ? "failed" : isEmpty ? "empty" : "mounted",
    isOverEntryBudget ? "BUDGET_EXCEEDED" : isEmpty ? "NO_DATA" : undefined,
    isOverEntryBudget
      ? `the List shows the first ${maxRepeatRows} of ${shortFormEntries.length} entries only`
      : isEmpty
        ? "the List has no entries"
        : undefined,
  );
  if (isEmpty) return <DataEmptyLine emptyText={nodeProps.emptyText} testId={`${testId}-empty`} />;
  const ListElement = marker === "number" ? "ol" : "ul";
  return (
    <ListElement className={classNames(styles.list, marker === "number" && styles.numbered)} data-testid={testId}>
      <ListContext.Provider value={listContextValue}>
        {shownEntries.map((entry, entryIndex) => (
          <ListEntry
            key={entryIndex}
            testId={`${testId}-entry-${entryIndex}`}
            marker={marker}
            title={entry.title}
            description={entry.description}
            meta={entry.meta}
            value={entry.value}
          />
        ))}
        <DataContainerContext.Provider value="list">{childEntries}</DataContainerContext.Provider>
      </ListContext.Provider>
    </ListElement>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "List", component: ListNode };
