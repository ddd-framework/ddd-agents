import { useEffect, useMemo, useState } from "react";
import { useDebouncedValue } from "./hooks/use-debounced-value";
import { useWidgetHydration } from "../renderer/widget-hydration";
import {
  composedViewDataSourceHydrationType,
  ComposedViewDataSourcePayload,
  ComposedViewSourceBinding,
  hasComposedViewDataSourceFetcher,
  resolveComposedViewSourceParams,
} from "../renderer/widget-tree/composed-view-data-sources";
import type { ExpressionValue } from "../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewSourceValue } from "../renderer/widget-tree/resolve-composed-view-values";

/** State-bound parameters settle for this long before the source is read again (a dragged slider reads once). */
const PARAM_DEBOUNCE_MS = 300;

interface ComposedViewDataSourceLoaderProps {
  sourceBinding: ComposedViewSourceBinding;
  state: Record<string, ExpressionValue>;
  agentSpaceKey: number | undefined;
  /** Whether this tree may start another read (the per-tree budget); a refused change keeps the last parameters. */
  mayRead: () => boolean;
  onValue: (bindingKey: string, sourceValue: ComposedViewSourceValue) => void;
}

/**
 * Reads ONE `$source` binding of a tree through the widget-hydration mechanism and hands the rows up.
 * Renders nothing; one component per binding, so the number of hooks never depends on the tree. The hydration reads
 * again whenever the payload changes, so a parameter change is first debounced, then counted against the tree's budget
 * (`maxSourceRequestsPerTreePerMinute`); a refused change keeps the rows of the last accepted parameters. A source
 * without a registered frontend fetcher fails at once (`UNRESOLVED_BINDING` in the report).
 */
export const ComposedViewDataSourceLoader = (props: ComposedViewDataSourceLoaderProps) => {
  const { sourceBinding, state, agentSpaceKey, mayRead, onValue } = props;
  const resolvedParams = useMemo(
    () => resolveComposedViewSourceParams(sourceBinding.writtenParams, state),
    [sourceBinding.writtenParams, state],
  );
  const debouncedParams = useDebouncedValue(resolvedParams, PARAM_DEBOUNCE_MS);
  const debouncedParamsJson = JSON.stringify(debouncedParams);
  const [acceptedPayload, setAcceptedPayload] = useState<ComposedViewDataSourcePayload>(() => ({
    params: resolvedParams,
    agentSpaceKey,
  }));

  useEffect(() => {
    const isUnchanged =
      JSON.stringify(acceptedPayload.params) === debouncedParamsJson && acceptedPayload.agentSpaceKey === agentSpaceKey;
    if (isUnchanged || !mayRead()) return;
    setAcceptedPayload({ params: JSON.parse(debouncedParamsJson) as Record<string, ExpressionValue>, agentSpaceKey });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reacts to the settled parameters only
  }, [debouncedParamsJson, agentSpaceKey]);

  const isRegistered = hasComposedViewDataSourceFetcher(sourceBinding.dataSourceName);
  const hydration = useWidgetHydration<ExpressionValue[], ComposedViewDataSourcePayload>(
    composedViewDataSourceHydrationType(isRegistered ? sourceBinding.dataSourceName : "unregistered"),
    acceptedPayload,
    { showLastDataWhileRefreshing: true },
  );

  useEffect(() => {
    if (!isRegistered || hydration.isError) {
      onValue(sourceBinding.bindingKey, { status: "failed", value: undefined });
    } else if (hydration.data !== null) {
      onValue(sourceBinding.bindingKey, { status: "resolved", value: hydration.data });
    } else {
      onValue(sourceBinding.bindingKey, { status: "pending", value: undefined });
    }
  }, [isRegistered, hydration.isError, hydration.data, sourceBinding.bindingKey, onValue]);

  return null;
};
