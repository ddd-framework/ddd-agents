import { useEffect } from "react";
/**
 * A choice input with no usable option renders nothing — and says so in the block's render report, so
 * the model learns the control did not show and answers in prose instead of "pick one above".
 */
export const useReportMissingOptions = (optionCount, nodeType, isPending, runtime) => {
    const { reportNodeStatus } = runtime;
    useEffect(() => {
        if (isPending || optionCount > 0)
            return;
        reportNodeStatus("failed", "INVALID_PROP", `${nodeType} has no usable option — it is not shown`);
    }, [optionCount, nodeType, isPending, reportNodeStatus]);
};
