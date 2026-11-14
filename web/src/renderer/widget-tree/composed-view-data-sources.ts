import { registerWidgetHydrator } from "../widget-hydration";
import { getComposedNodeSchema, ComposedNodeSchema } from "./composed-node-schema";
import {
  ComposedViewNode,
  ComposedViewTree,
  getComposedViewSubtreeNodes,
  JsonValue,
  readComposedViewBinding,
} from "./composed-view-tree";
import type { ExpressionValue } from "./expression/evaluate-expression";
import { composedViewSourceBindingKey } from "./resolve-composed-view-values";

/**
 * The frontend side of the `$source` data path: a registered source is read through the existing
 * widget-hydration mechanism (`widget-hydration/`), so the e-mail capture waits for it like for any self-hydrating
 * widget. A source is added with TWO files: the backend `<Name>ComposedViewDataSource` (endpoint, params,
 * columns — exported into the schema) and the frontend fetcher `<name>.composed-data-source.ts` anywhere below
 * `widgets/` exporting `composedViewDataSourceFetcher` (it reads the endpoint through the generated SDK store and returns
 * the response; the rows are taken at the schema's `rowsPath`). The read rights are the endpoint's own entity rights,
 * server-side — the fetcher passes the agent space key and the parameters, nothing else.
 */

export interface ComposedViewDataSourceFetcher {
  /** The source name (the backend `NAME`). */
  name: string;
  /** Reads the source for an agent space and the resolved parameters; resolves with the endpoint's response. */
  fetchResponse: (
    params: Record<string, ExpressionValue>,
    agentSpaceKey: number | undefined,
    signal: AbortSignal,
  ) => Promise<unknown>;
}

export interface ComposedViewDataSourcePayload {
  params: Record<string, ExpressionValue>;
  agentSpaceKey: number | undefined;
}

/** The hydration type a source registers under. */
export const composedViewDataSourceHydrationType = (dataSourceName: string): string =>
  `ComposedViewDataSource:${dataSourceName}`;

/** The rows at a schema `rowsPath` (`$`, `$.field`, `$.a.b`) of a response; undefined when the path does not hold a list. */
export const readRowsAtPath = (response: unknown, rowsPath: string): ExpressionValue[] | undefined => {
  let current: unknown = response;
  for (const segment of rowsPath
    .replace(/^\$\.?/, "")
    .split(".")
    .filter(Boolean)) {
    if (typeof current !== "object" || current === null || !Object.prototype.hasOwnProperty.call(current, segment)) {
      return undefined;
    }
    current = (current as Record<string, unknown>)[segment];
  }
  return Array.isArray(current) ? (current as ExpressionValue[]) : undefined;
};

const registeredFetcherNames = new Set<string>();

/** Registers a fetcher as a widget hydrator; the rows land in the hydration memory like any widget's data. */
export const registerComposedViewDataSourceFetcher = (
  fetcher: ComposedViewDataSourceFetcher,
  schema: ComposedNodeSchema = getComposedNodeSchema(),
): void => {
  const dataSourceSchema = schema.dataSources?.[fetcher.name];
  registeredFetcherNames.add(fetcher.name);
  registerWidgetHydrator<ComposedViewDataSourcePayload, ExpressionValue[]>(
    composedViewDataSourceHydrationType(fetcher.name),
    {
      hydrator: async (payload, context) => {
        const response = await fetcher.fetchResponse(payload.params, payload.agentSpaceKey, context.signal);
        const rows = readRowsAtPath(response, dataSourceSchema?.rowsPath ?? "$");
        if (rows === undefined)
          throw new Error(`the data source "${fetcher.name}" returned no rows at ${dataSourceSchema?.rowsPath ?? "$"}`);
        return rows.slice(0, schema.budgets.maxCollectionItems);
      },
      deriveKey: (type, payload) => `${type}:${payload.agentSpaceKey ?? "active"}:${JSON.stringify(payload.params)}`,
      staleMs: (dataSourceSchema?.freshnessSeconds ?? 300) * 1000,
    },
  );
};

/** Whether a fetcher is registered for a source name. */
export const hasComposedViewDataSourceFetcher = (dataSourceName: string): boolean =>
  registeredFetcherNames.has(dataSourceName);

const fetcherModules = import.meta.glob<{ composedViewDataSourceFetcher?: ComposedViewDataSourceFetcher }>(
  ["../../widgets/**/*.composed-data-source.ts", "../../kit/**/*.composed-data-source.ts"],
  { eager: true },
);
for (const fetcherModule of Object.values(fetcherModules)) {
  if (fetcherModule.composedViewDataSourceFetcher)
    registerComposedViewDataSourceFetcher(fetcherModule.composedViewDataSourceFetcher);
}

/** One `$source` binding of a tree, with its parameters as written (`$state` bindings inside). */
export interface ComposedViewSourceBinding {
  /** The binding's identity in the tree: name + parameters as written. */
  bindingKey: string;
  dataSourceName: string;
  writtenParams: Record<string, JsonValue>;
}

const collectFromValue = (value: unknown, sourceBindings: Map<string, ComposedViewSourceBinding>): void => {
  const binding = readComposedViewBinding(value);
  if (binding !== null) {
    const bindingKey = composedViewSourceBindingKey(value);
    if (bindingKey !== null && !sourceBindings.has(bindingKey)) {
      sourceBindings.set(bindingKey, {
        bindingKey,
        dataSourceName: binding.target,
        writtenParams: binding.sourceParams as Record<string, JsonValue>,
      });
    }
    return;
  }
  if (Array.isArray(value)) value.forEach((element) => collectFromValue(element, sourceBindings));
  else if (typeof value === "object" && value !== null)
    Object.values(value).forEach((nested) => collectFromValue(nested, sourceBindings));
};

/** Every distinct `$source` binding of a tree — its datasets and every node's props — in tree order. */
export const collectComposedViewSourceBindings = (tree: ComposedViewTree): ComposedViewSourceBinding[] => {
  const sourceBindings = new Map<string, ComposedViewSourceBinding>();
  collectFromValue(tree.data, sourceBindings);
  const nodes: ComposedViewNode[] = tree.view ? getComposedViewSubtreeNodes(tree.view) : [];
  for (const node of nodes) collectFromValue(node.props, sourceBindings);
  return [...sourceBindings.values()];
};

/** The parameters with their `$state` bindings resolved against the instance state. */
export const resolveComposedViewSourceParams = (
  writtenParams: Record<string, JsonValue>,
  state: Record<string, ExpressionValue>,
): Record<string, ExpressionValue> =>
  Object.fromEntries(
    Object.entries(writtenParams).map(([paramName, paramValue]) => {
      const paramBinding = readComposedViewBinding(paramValue);
      if (paramBinding?.kind === "state") {
        return [
          paramName,
          Object.prototype.hasOwnProperty.call(state, paramBinding.target) ? state[paramBinding.target] : undefined,
        ];
      }
      return [paramName, paramValue as ExpressionValue];
    }),
  );
