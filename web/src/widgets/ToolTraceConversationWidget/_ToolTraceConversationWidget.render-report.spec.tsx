// @vitest-environment jsdom
import { render, screen } from "@testing-library/react";
import { describe, expect, test } from "vitest";
import {
  WidgetRenderReportContext,
  type WidgetRenderReportEntry,
} from "../../renderer/render-report/widget-render-report";
import { ToolTraceConversationWidget } from "./ToolTraceConversationWidget";

/** An unreadable trace payload renders nothing and reports `failed` instead of vanishing silently. */
const renderReporting = (payload: string) => {
  const reports: WidgetRenderReportEntry[] = [];
  const view = render(
    <WidgetRenderReportContext.Provider value={{ report: (entry) => reports.push(entry) }}>
      <ToolTraceConversationWidget payload={payload} />
    </WidgetRenderReportContext.Provider>,
  );
  return { ...view, reports };
};

describe("ToolTraceConversationWidget render report", () => {
  test("a payload that is not a JSON object renders nothing and reports failed INVALID_PROP", () => {
    const { container, reports } = renderReporting("{not json");
    expect(container).toBeEmptyDOMElement();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("a readable trace reports mounted", () => {
    const { reports } = renderReporting(JSON.stringify({ name: "getRankings" }));
    expect(screen.getByText(/Opened/)).toBeInTheDocument();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });
});
