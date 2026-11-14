import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import {
  DataValueAlign,
  DataValueFormat,
  isNumericValue,
  readDataValueAlign,
  readDataValueFormat,
  readFiniteNumber,
  readObject,
  readText,
} from "./data-value-format";

/** One column of a `Table`'s short form (`columns`): the row field, its header and how its cells are shown. */
export interface TableColumn {
  key: string;
  label: string;
  align: DataValueAlign;
  format: DataValueFormat;
  currency?: string;
  decimals?: number;
  unit?: string;
}

/**
 * The short form's columns from the resolved `columns` prop: every entry with a string `key` (alias `field`, which a
 * model writes because `Chart` series use it); the header falls back to the key; a column without an `align` aligns
 * at the end when every value in it is a number.
 */
export const readTableColumns = (
  columnsValue: ExpressionValue,
  rows: Record<string, ExpressionValue>[],
): TableColumn[] => {
  if (!Array.isArray(columnsValue)) return [];
  const columns: TableColumn[] = [];
  for (const columnValue of columnsValue) {
    const column = readObject(columnValue);
    const key = readText(column?.key) ?? readText(column?.field);
    if (!column || !key) continue;
    const values = rows.map((row) => row[key]).filter((value) => value !== undefined && value !== null && value !== "");
    const isNumericColumn = values.length > 0 && values.every((value) => isNumericValue(value));
    columns.push({
      key,
      label: readText(column.label) ?? key,
      align: readDataValueAlign(column.align, isNumericColumn ? "end" : "start"),
      format: readDataValueFormat(column.format),
      currency: readText(column.currency),
      decimals: readFiniteNumber(column.decimals),
      unit: readText(column.unit),
    });
  }
  return columns;
};
