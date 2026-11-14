import { classNames } from "../../../provider/class-names";
import { useMemo } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { DataContainerContext, TableRowContext, TableRowContextValue, useDataContainer } from "./data-containers";
import styles from "./Table.module.scss";

/**
 * The `TableRow` node (`Row.cells`): a `<tr>` of its `TableCell`s; `header` makes the cells
 * column headers, `emphasis` a bold total row. Outside a `Table` (a row at the root, a `Repeat` of rows without a
 * table) it wraps itself in a one-row table, so the markup stays valid.
 */
const TableRowNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, testId } = props;
  const container = useDataContainer();
  const isHeader = nodeProps.header === true;
  const isEmphasis = nodeProps.emphasis === true;
  const tableRowContextValue = useMemo<TableRowContextValue>(() => ({ isHeader, isEmphasis }), [isHeader, isEmphasis]);
  const row = (
    <tr className={classNames(styles.row, isHeader && styles.headerRow)} data-testid={testId}>
      <TableRowContext.Provider value={tableRowContextValue}>{slots.children}</TableRowContext.Provider>
    </tr>
  );
  if (container === "table") return row;
  return (
    <div className={styles.scroller}>
      <table className={styles.grid}>
        <tbody>
          <DataContainerContext.Provider value="table">{row}</DataContainerContext.Provider>
        </tbody>
      </table>
    </div>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "TableRow", component: TableRowNode };
