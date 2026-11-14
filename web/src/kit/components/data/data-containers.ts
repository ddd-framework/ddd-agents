import { createContext, useContext } from "react";
/**
 * Where a data node sits: a `TableRow` must know it is inside a `Table` to render a `<tr>`,
 * a `TableCell` inside a header row to render a `<th>`, a `Repeat` inside a `Table` or `List` to repeat only the row
 * nodes those containers take. Outside its container each node renders a standalone fallback instead of invalid
 * markup — a model may put a `TableRow` at the root, the reader does not forbid it.
 */

export type DataContainerKind = "table" | "list" | null;

export const DataContainerContext = createContext<DataContainerKind>(null);
export const useDataContainer = () => useContext(DataContainerContext);

export interface TableRowContextValue {
  isHeader: boolean;
  isEmphasis: boolean;
}

export const TableRowContext = createContext<TableRowContextValue | null>(null);
export const useTableRow = () => useContext(TableRowContext);

export interface ListContextValue {
  marker: "none" | "bullet" | "number" | "check";
}

export const ListContext = createContext<ListContextValue>({ marker: "none" });
export const useListMarker = () => useContext(ListContext).marker;

/** The node types a container takes from a `Repeat` inside it. */
export const REPEATABLE_NODE_TYPES_BY_CONTAINER: Record<Exclude<DataContainerKind, null>, string> = {
  table: "TableRow",
  list: "ListItem",
};
