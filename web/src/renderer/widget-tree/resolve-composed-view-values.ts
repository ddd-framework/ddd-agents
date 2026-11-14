import type { ExpressionValue } from "./expression/evaluate-expression";
import { JsonValue, readComposedViewBinding, readComposedViewRowField } from "./composed-view-tree";

/**
 * Turns bound props and datasets of a `ComposedView` tree into values. A binding is resolved in the
 * browser from what the tree already holds: `$state` (the instance state), `$computed` (the evaluated expressions),
 * `$data` (a dataset), `$item` (a field of the current row of an item scope — a dotted `author.name` reads a nested
 * object, `readComposedViewRowField`), `$from` (the snapshot the backend writes INTO the
 * binding when the row is stored — `{"$from": {"toolCall", "path", "state": "resolved", "value": …}}`;
 * before that the binding is `pending`) and `$source` (a registered data source the frontend hydrates through the
 * widget-hydration mechanism, `composed-view-data-sources.ts`; looked up by its binding key, `pending` until the rows
 * arrive). A value that does not resolve is `undefined` and the prop renders empty.
 */

export type ResolvedBindingStatus = "resolved" | "pending" | "failed";

export interface ComposedViewValueScope {
  state: Record<string, ExpressionValue>;
  computed: Record<string, ExpressionValue>;
  datasets: Record<string, ExpressionValue>;
  /**
   * Each dataset's binding status (`resolveComposedViewDatasets().statusByDatasetName`): a prop bound with `$data` to a
   * dataset whose `$from` / `$source` is still pending is pending itself, exactly like a direct `$from` binding.
   */
  datasetStatuses?: Record<string, ResolvedBindingStatus>;
  /** The current row of the innermost item scope (`Repeat`), when inside one. */
  item?: ExpressionValue;
  /** The hydrated `$source` values, by binding key (`composedViewSourceBindingKey`). */
  sources?: Record<string, ComposedViewSourceValue>;
}

export interface ComposedViewSourceValue {
  status: ResolvedBindingStatus;
  value: ExpressionValue;
}

/** The identity of a `$source` wire value — its name and parameters as written; null for another value. */
export const composedViewSourceBindingKey = (wireValue: unknown): string | null => {
  const binding = readComposedViewBinding(wireValue);
  if (binding === null || binding.kind !== "source" || !binding.isWellFormed) return null;
  return `${binding.target}|${JSON.stringify(binding.sourceParams)}`;
};

const readSourceValue = (
  wireValue: unknown,
  sourceValuesByBindingKey: Record<string, ComposedViewSourceValue> | undefined,
): ComposedViewSourceValue => {
  const bindingKey = composedViewSourceBindingKey(wireValue);
  if (bindingKey === null) return { status: "failed", value: undefined };
  return sourceValuesByBindingKey?.[bindingKey] ?? { status: "pending", value: undefined };
};

export interface ResolvedComposedViewDatasets {
  datasets: Record<string, ExpressionValue>;
  statusByDatasetName: Record<string, ResolvedBindingStatus>;
}

const hasOwn = (object: object, key: string): boolean => Object.prototype.hasOwnProperty.call(object, key);

/** The value and status a `$from` binding's stored snapshot carries. */
export const readFromBindingSnapshot = (
  fromBindingObject: Record<string, unknown>,
): { status: ResolvedBindingStatus; value: ExpressionValue } => {
  const snapshotState = fromBindingObject.state;
  if (snapshotState === "resolved") return { status: "resolved", value: fromBindingObject.value as ExpressionValue };
  if (snapshotState === "failed") return { status: "failed", value: undefined };
  return { status: "pending", value: undefined };
};

/**
 * The tree's datasets as values: inline rows as written, `$from` from its snapshot, `$source` from
 * `sourceValuesByBindingKey` (filled by the data-source hydration; pending without).
 */
export const resolveComposedViewDatasets = (
  data: Record<string, JsonValue>,
  sourceValuesByBindingKey: Record<string, ComposedViewSourceValue> = {},
): ResolvedComposedViewDatasets => {
  const resolved: ResolvedComposedViewDatasets = { datasets: {}, statusByDatasetName: {} };
  for (const [datasetName, dataset] of Object.entries(data)) {
    const binding = readComposedViewBinding(dataset);
    if (binding === null) {
      resolved.datasets[datasetName] = dataset as ExpressionValue;
      resolved.statusByDatasetName[datasetName] = "resolved";
      continue;
    }
    const snapshot =
      binding.kind === "from"
        ? readFromBindingSnapshot((binding.wireValue.$from ?? {}) as Record<string, unknown>)
        : readSourceValue(dataset, sourceValuesByBindingKey);
    resolved.datasets[datasetName] = snapshot.value;
    resolved.statusByDatasetName[datasetName] = snapshot.status;
  }
  return resolved;
};

/** One prop value with its bindings resolved (nested bindings in inline data included). */
export const resolveComposedViewValue = (
  wireValue: JsonValue | undefined,
  scope: ComposedViewValueScope,
  onBindingStatus?: (status: ResolvedBindingStatus) => void,
): ExpressionValue => {
  const binding = readComposedViewBinding(wireValue);
  if (binding !== null) {
    switch (binding.kind) {
      case "state":
        return hasOwn(scope.state, binding.target) ? scope.state[binding.target] : undefined;
      case "computed":
        return hasOwn(scope.computed, binding.target) ? scope.computed[binding.target] : undefined;
      case "data": {
        const datasetStatus = scope.datasetStatuses?.[binding.target];
        if (datasetStatus !== undefined) onBindingStatus?.(datasetStatus);
        return hasOwn(scope.datasets, binding.target) ? scope.datasets[binding.target] : undefined;
      }
      case "item": {
        const rowField = readComposedViewRowField(scope.item, binding.target);
        return rowField.found ? (rowField.value as ExpressionValue) : undefined;
      }
      case "from": {
        const snapshot = readFromBindingSnapshot((binding.wireValue.$from ?? {}) as Record<string, unknown>);
        onBindingStatus?.(snapshot.status);
        return snapshot.value;
      }
      case "source": {
        const sourceValue = readSourceValue(wireValue, scope.sources);
        onBindingStatus?.(sourceValue.status);
        return sourceValue.value;
      }
      default:
        onBindingStatus?.("pending");
        return undefined;
    }
  }
  if (Array.isArray(wireValue))
    return wireValue.map((element) => resolveComposedViewValue(element, scope, onBindingStatus));
  if (typeof wireValue === "object" && wireValue !== null) {
    return Object.fromEntries(
      Object.entries(wireValue).map(([key, nested]) => [key, resolveComposedViewValue(nested, scope, onBindingStatus)]),
    );
  }
  return wireValue;
};
