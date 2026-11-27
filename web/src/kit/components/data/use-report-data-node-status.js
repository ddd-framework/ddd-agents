import { useEffect } from "react";
/**
 * Reports a data node's status into the block's render report on every change — `mounted` included, so
 * a node that was `empty` or `pending` and then got its rows clears its earlier report instead of leaving it standing.
 */
export const useReportDataNodeStatus = (runtime, status, code, detail) => {
    const { reportNodeStatus } = runtime;
    useEffect(() => {
        reportNodeStatus(status, code, detail);
    }, [reportNodeStatus, status, code, detail]);
};
