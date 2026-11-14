import { classNames } from "../../../provider/class-names";
import { useEffect } from "react";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readEnumProp, readGapProp } from "./layout-props";
import { layoutGapClassName } from "./layout-gap-class";
import styles from "./Grid.module.scss";

const COLUMN_COUNTS = [1, 2, 3, 4] as const;
const MIN_COLUMN_WIDTHS = ["compact", "regular"] as const;

/**
 * The cells a compact column fits: a number tile, a pressable tile, a status, a button. Any other cell — a surface with
 * a title and a description, a text, a chart — needs the regular minimum, or a phone shows two columns of four-word lines.
 */
const COMPACT_CELL_NODE_TYPES: ReadonlySet<string> = new Set([
  "KpiTile",
  "Pressable",
  "Badge",
  "Icon",
  "Favicon",
  "Button",
]);

/** A cell that fits a compact column: a compact node type, or an embedded widget whose size class is compact (a snapshot tile). */
const isCompactCell = (cellNode: ComposedViewComponentProps["node"]): boolean => {
  if (COMPACT_CELL_NODE_TYPES.has(cellNode.type)) return true;
  const embeddedWidgetName = cellNode.type === "Widget" ? cellNode.props.widget : undefined;
  const embeddableWidgets = getComposedNodeSchema().embeddableWidgets;
  return (
    typeof embeddedWidgetName === "string" &&
    Object.prototype.hasOwnProperty.call(embeddableWidgets, embeddedWidgetName) &&
    embeddableWidgets[embeddedWidgetName].sizeClass === "compact"
  );
};

/** The written minimum wins; KPI-only grids use regular width, otherwise the existing compact-cell rules apply. */
export const readMinColumnWidth = (node: ComposedViewComponentProps["node"], writtenMinColumnWidth: unknown) => {
  if (MIN_COLUMN_WIDTHS.includes(writtenMinColumnWidth as (typeof MIN_COLUMN_WIDTHS)[number])) {
    return writtenMinColumnWidth as (typeof MIN_COLUMN_WIDTHS)[number];
  }
  const cellNodes = node.nodesBySlotName.children ?? [];
  if (cellNodes.length > 0 && cellNodes.every((cell) => cell.type === "KpiTile")) return "regular";
  return cellNodes.every(isCompactCell) ? "compact" : "regular";
};

const COLUMN_CLASS_NAMES: Record<(typeof COLUMN_COUNTS)[number], string> = {
  1: styles.columns1,
  2: styles.columns2,
  3: styles.columns3,
  4: styles.columns4,
};

/**
 * `Grid` (`GridComposedViewNode.php`): one cell per child, at most `columns` side by side, every
 * cell in a row as tall as the tallest. On a narrow pane it shows fewer columns (the cell keeps its minimum width). The
 * minimum is the written `minColumnWidth`; without one KPI-only grids take the regular minimum so textual KPI values
 * stay readable. Other grids keep their compact-cell default.
 * A grid without children shows nothing and reports `empty`.
 */
const Grid = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, slots, runtime, testId } = props;
  const cells = slots.children ?? [];
  const columnCount = readEnumProp(nodeProps.columns, COLUMN_COUNTS, 2);
  const minColumnWidth = readMinColumnWidth(node, nodeProps.minColumnWidth);
  const hasCells = cells.length > 0;
  const { reportNodeStatus } = runtime;

  useEffect(() => {
    if (hasCells) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Grid has no cells to show");
  }, [hasCells, reportNodeStatus]);

  if (!hasCells) return null;
  return (
    <div
      data-testid={testId}
      className={classNames(
        styles.grid,
        COLUMN_CLASS_NAMES[columnCount],
        minColumnWidth === "regular" ? styles.minColumnRegular : styles.minColumnCompact,
        layoutGapClassName(styles, readGapProp(nodeProps.gap)),
      )}
    >
      {cells}
    </div>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Grid", component: Grid };
