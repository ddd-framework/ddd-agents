import { useEffect } from "react";
import type { ComposedViewRuntime } from "../../../composed-view-component-types";

/**
 * A choice input with no usable option renders nothing — and says so in the block's render report, so
 * the model learns the control did not show and answers in prose instead of "pick one above".
 */
export const useReportMissingOptions = (
  optionCount: number,
  nodeType: string,
  isPending: boolean,
  runtime: ComposedViewRuntime,
): void => {
  const { reportNodeStatus } = runtime;
  useEffect(() => {
    if (isPending || optionCount > 0) return;
    reportNodeStatus("failed", "INVALID_PROP", `${nodeType} has no usable option — it is not shown`);
  }, [optionCount, nodeType, isPending, reportNodeStatus]);
};
