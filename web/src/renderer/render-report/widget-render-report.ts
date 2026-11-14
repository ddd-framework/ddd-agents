import { createContext, useContext, useEffect } from "react";

/**
 * The render report of one widget block: after mount every block says whether it showed what it should —
 * `mounted`, `empty` (hydrated, nothing to show), `failed` (error boundary, hydration error, invalid tree) or `pending`
 * (still waiting for data; never a final answer). The chat host collects the statuses per message and tells the model
 * about the blocks that are not `mounted` (the `FRONTEND_WIDGET_RENDER_REPORT` signal). A widget reports
 * through {@link useReportWidgetRenderStatus}; a widget that reports nothing counts as `mounted` once it rendered
 * without throwing.
 */

export type WidgetRenderStatus = "mounted" | "empty" | "failed" | "pending";

/**
 * Why a block is not `mounted`. The tree codes are the backend's (`ComposedViewValidationFinding`); the runtime codes
 * name what went wrong in the browser.
 */
export type WidgetRenderReportCode =
  | "INVALID_NODE"
  | "INVALID_PROP"
  | "UNKNOWN_BINDING"
  | "INVALID_EXPRESSION"
  | "INVALID_STATE"
  | "INVALID_ACTION"
  | "BUDGET_EXCEEDED"
  | "INLINE_VALUE_WITHOUT_SOURCE"
  | "INVALID_INITIAL_CHOICE"
  | "UNREAD_STATE"
  | "UNKNOWN_ITEM_KEY"
  | "INVALID_SLOT_NODE_COUNT"
  | "WIDGET_LIFTED"
  | "UNRESOLVED_BINDING"
  | "UNRESOLVED_REF"
  | "HYDRATION_FAILED"
  | "RENDER_FAILED"
  | "NO_DATA";

export interface WidgetRenderReportEntry {
  status: WidgetRenderStatus;
  code?: WidgetRenderReportCode;
  /** One short English sentence for the model (what was dropped / what failed); never customer data beyond names. */
  detail?: string;
}

/** What a block reports into — provided by the chat host per block; absent = nobody listens (a story, a test). */
export interface WidgetRenderReporter {
  report: (entry: WidgetRenderReportEntry) => void;
}

export const WidgetRenderReportContext = createContext<WidgetRenderReporter | null>(null);

/** Reports the block's status whenever it changes; a no-op without a reporter. */
export const useReportWidgetRenderStatus = (
  status: WidgetRenderStatus,
  code?: WidgetRenderReportCode,
  detail?: string,
): void => {
  const reporter = useContext(WidgetRenderReportContext);
  useEffect(() => {
    reporter?.report({ status, ...(code ? { code } : {}), ...(detail ? { detail } : {}) });
  }, [reporter, status, code, detail]);
};

/** The reporter itself, for a widget that reports from an event (a failed action) rather than from a render. */
export const useWidgetRenderReporter = (): WidgetRenderReporter | null => useContext(WidgetRenderReportContext);
