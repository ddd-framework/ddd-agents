import { createContext, useContext } from "react";
export const DataContainerContext = createContext(null);
export const useDataContainer = () => useContext(DataContainerContext);
export const TableRowContext = createContext(null);
export const useTableRow = () => useContext(TableRowContext);
export const ListContext = createContext({ marker: "none" });
export const useListMarker = () => useContext(ListContext).marker;
/** The node types a container takes from a `Repeat` inside it. */
export const REPEATABLE_NODE_TYPES_BY_CONTAINER = {
    table: "TableRow",
    list: "ListItem",
};
