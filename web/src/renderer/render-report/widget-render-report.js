import { createContext, useContext, useEffect } from "react";
export const WidgetRenderReportContext = createContext(null);
/** Reports the block's status whenever it changes; a no-op without a reporter. */
export const useReportWidgetRenderStatus = (status, code, detail) => {
    const reporter = useContext(WidgetRenderReportContext);
    useEffect(() => {
        reporter?.report({ status, ...(code ? { code } : {}), ...(detail ? { detail } : {}) });
    }, [reporter, status, code, detail]);
};
/** The reporter itself, for a widget that reports from an event (a failed action) rather than from a render. */
export const useWidgetRenderReporter = () => useContext(WidgetRenderReportContext);
