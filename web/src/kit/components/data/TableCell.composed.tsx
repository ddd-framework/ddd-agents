import { classNames } from "../../../provider/class-names";
import { CSSProperties } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { DataContainerContext, useTableRow } from "./data-containers";
import {
  formatDataValue,
  isNumericValue,
  readDataValueAlign,
  readDataValueFormat,
  readDataValueTone,
  readFiniteNumber,
  readFraction,
  readText,
} from "./data-value-format";
import { TableValue } from "./TableValue/TableValue";
import { useNumberLocale } from "./use-number-locale";
import tableStyles from "./Table.module.scss";
import styles from "./TableCell.module.scss";

/**
 * The `TableCell` node (`Cell.content`): a formatted `value`, then the nodes of its compact slot
 * (an `Icon`, a `Badge`, a sparkline `Chart`) — the cell is the place where a table holds other nodes. `bar` draws a
 * share bar before the value, `heat` tints the whole cell by intensity with the value on top (no value = a muted dash on the untinted tile). In a header row the cell is a `<th>`; outside a row it is a plain
 * inline block. The bar and heat widths travel as CSS custom properties — the one dynamic value, no inline style.
 */
const TableCellNode = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, slots } = props;
  const tableRow = useTableRow();
  const locale = useNumberLocale();
  const isHeader = tableRow?.isHeader ?? false;
  const isBold = isHeader || (tableRow?.isEmphasis ?? false);
  const align = readDataValueAlign(nodeProps.align, isNumericValue(nodeProps.value) ? "end" : "start");
  const format = readDataValueFormat(nodeProps.format);
  const bar = readFraction(nodeProps.bar);
  const heat = readFraction(nodeProps.heat);
  // A heat cell is one whose node declares `heat`, even when its binding resolved to nothing (a missing cohort month).
  const isHeatCell = Object.prototype.hasOwnProperty.call(node.props, "heat");
  const hasValue = formatDataValue(nodeProps.value, { format, locale }) !== "" || typeof nodeProps.value === "boolean";
  const cellStyle = {
    ...(bar !== undefined ? { "--agents-table-cell-bar": `${Math.round(bar * 1000) / 10}%` } : {}),
    ...(heat !== undefined ? { "--agents-table-cell-heat": `${Math.round(10 + heat * 70)}%` } : {}),
  } as CSSProperties;
  const content = (
    <span
      className={classNames(
        styles.content,
        styles[align],
        bar !== undefined && styles.withBar,
        isHeatCell && styles.heat,
        isHeatCell && heat === undefined && styles.heatEmpty,
      )}
    >
      {bar !== undefined ? <span className={styles.bar} aria-hidden="true" /> : null}
      {hasValue ? (
        <TableValue
          value={nodeProps.value}
          format={format}
          currency={readText(nodeProps.currency)}
          decimals={readFiniteNumber(nodeProps.decimals)}
          unit={readText(nodeProps.unit)}
          signed={nodeProps.signed === true}
          tone={readDataValueTone(nodeProps.tone)}
          isBold={isBold}
        />
      ) : isHeatCell ? (
        <span className={styles.dash}>{"—"}</span>
      ) : null}
      {slots.children && slots.children.length > 0 ? (
        <DataContainerContext.Provider value={null}>{slots.children}</DataContainerContext.Provider>
      ) : null}
    </span>
  );
  if (!tableRow) return <span style={cellStyle}>{content}</span>;
  const cellClassName = classNames(tableStyles.cell, tableStyles[align], isHeatCell && styles.heatCell);
  return isHeader ? (
    <th scope="col" className={cellClassName} style={cellStyle}>
      {content}
    </th>
  ) : (
    <td className={cellClassName} style={cellStyle}>
      {content}
    </td>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "TableCell",
  component: TableCellNode,
};
