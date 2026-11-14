import { Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { DataContainerContext } from "./data-containers";
import { readRows } from "./data-value-format";
import { readTableColumns } from "./table-columns";
import { TableValue } from "./TableValue/TableValue";
import { DataFootnote } from "./DataFootnote/DataFootnote";
import { DataEmptyLine } from "./DataEmptyLine/DataEmptyLine";
import { useReportDataNodeStatus } from "./use-report-data-node-status";
import styles from "./Table.module.scss";

const SKELETON_ROW_COUNT = 3;

/**
 * The `Table` node. The short form — `columns` + `rows` — writes the
 * header row and one row per data row, each cell formatted by its column (a boolean as a check or a cross). The node
 * form — `TableRow` / `Repeat` children — follows it in the same body, so a total row can close the data rows. Rows
 * are hairline-divided, never boxed; a table wider than the chat pane scrolls inside itself. A
 * table still waiting for bound rows shows row-shaped skeletons; one with nothing to show one muted line and reports
 * `empty`. The short form renders at most `maxRepeatRows` rows and reports the rest.
 */
const TableNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, runtime, isPending, testId } = props;
  const maxRepeatRows = composedNodeSchema.budgets.maxRepeatRows;
  const rows = readRows(nodeProps.rows) ?? [];
  const columns = readTableColumns(nodeProps.columns, rows);
  const shownRows = rows.slice(0, maxRepeatRows);
  const childRows = slots.children ?? [];
  const isWaitingForRows = isPending && rows.length === 0;
  const isEmpty = !isPending && (columns.length === 0 || rows.length === 0) && childRows.length === 0;
  const isOverRowBudget = rows.length > maxRepeatRows;
  useReportDataNodeStatus(
    runtime,
    isPending ? "pending" : isOverRowBudget ? "failed" : isEmpty ? "empty" : "mounted",
    isOverRowBudget ? "BUDGET_EXCEEDED" : isEmpty && !isPending ? "NO_DATA" : undefined,
    isOverRowBudget
      ? `the Table shows the first ${maxRepeatRows} of ${rows.length} rows only`
      : isEmpty && !isPending
        ? "the Table has no rows"
        : undefined,
  );
  return (
    <div className={styles.table} data-testid={testId}>
      <div className={styles.scroller}>
        <table className={styles.grid}>
          {columns.length > 0 ? (
            <thead>
              <tr>
                {columns.map((column) => (
                  <th key={column.key} scope="col" className={classNames(styles.cell, styles[column.align])}>
                    <Text role="help" weight="bold" translate={false}>
                      {escapePlainTextForText(column.label)}
                    </Text>
                  </th>
                ))}
              </tr>
            </thead>
          ) : null}
          <tbody>
            {shownRows.map((row, rowIndex) => (
              <tr key={rowIndex} className={styles.row} data-testid={`${testId}-row-${rowIndex}`}>
                {columns.map((column) => (
                  <td key={column.key} className={classNames(styles.cell, styles[column.align])}>
                    <TableValue
                      value={row[column.key]}
                      format={column.format}
                      currency={column.currency}
                      decimals={column.decimals}
                      unit={column.unit}
                    />
                  </td>
                ))}
              </tr>
            ))}
            {isWaitingForRows && columns.length > 0
              ? Array.from({ length: SKELETON_ROW_COUNT }, (unused, skeletonIndex) => (
                  <tr
                    key={`skeleton-${skeletonIndex}`}
                    className={styles.row}
                    aria-busy="true"
                    data-testid={`${testId}-skeleton-${skeletonIndex}`}
                  >
                    {columns.map((column) => (
                      <td key={column.key} className={classNames(styles.cell, styles[column.align])}>
                        <SkeletonLine width="70%" height={12} />
                      </td>
                    ))}
                  </tr>
                ))
              : null}
            <DataContainerContext.Provider value="table">{childRows}</DataContainerContext.Provider>
          </tbody>
        </table>
      </div>
      {isEmpty ? <DataEmptyLine emptyText={nodeProps.emptyText} testId={`${testId}-empty`} /> : null}
      <DataFootnote caption={nodeProps.caption} source={nodeProps.source} isEmailRender={runtime.isEmailRender} />
    </div>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Table", component: TableNode };
