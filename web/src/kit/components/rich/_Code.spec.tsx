// @vitest-environment jsdom
import { describe, expect, test, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
import { CODE_COLLAPSED_LINE_COUNT } from "./Code.composed";

/**
 * The `Code` node through the renderer: the code shows at once and is highlighted when its grammar
 * loaded, the source's own markup stays text, copy writes the code to the clipboard, the wrap toggle switches the line
 * wrapping, long code collapses behind "Show all lines", an unknown language stays plain, the e-mail capture shows no
 * controls, and empty code reports `empty`.
 */
const PHP_CODE = [
  "<?php",
  "",
  "declare(strict_types=1);",
  "",
  "function conversionRate(int $conversions, int $visitors): float {",
  "    return $visitors > 0 ? ($conversions / $visitors) * 100.0 : 0.0;",
  "}",
  "",
  "echo conversionRate(750, 25000); // 3",
].join("\n");

const renderTree = (widgetFields: Record<string, unknown>, isEmailRender = false) => {
  const reports: WidgetRenderReportEntry[] = [];
  const tree = readComposedViewTree(widgetFields, getComposedNodeSchema());
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  const { container } = render(
    <Provider store={store}>
      <ComposedViewRenderer
        tree={tree}
        interactive={!isEmailRender}
        isEmailRender={isEmailRender}
        agentSpaceKey={7}
        onReport={(entry) => reports.push(entry)}
      />
    </Provider>,
  );
  return { reports, container, tree };
};

describe("the Code node", () => {
  test("PHP code is highlighted under its language name and reports mounted", async () => {
    const { reports, container, tree } = renderTree({ view: { type: "Code", code: PHP_CODE, language: "php" } });
    expect(tree.findings).toEqual([]);
    expect(screen.getByText("PHP")).toBeTruthy();
    await waitFor(() => expect(container.querySelector(".hljs-keyword")).not.toBeNull());
    expect(container.querySelector("code")?.textContent).toBe(PHP_CODE);
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("markup inside the code stays text", async () => {
    const { container } = renderTree({
      view: { type: "Code", code: "<img src=x onerror=alert(1)>", language: "html" },
    });
    await waitFor(() => expect(container.querySelector(".hljs-tag")).not.toBeNull());
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("code")?.textContent).toBe("<img src=x onerror=alert(1)>");
  });

  test("copy writes the code to the clipboard and confirms it", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    renderTree({ view: { type: "Code", code: "SELECT 1;", language: "sql" } });
    fireEvent.click(screen.getByText("Copy"));
    expect(writeText).toHaveBeenCalledWith("SELECT 1;");
    await waitFor(() => expect(screen.getByText("Copied")).toBeTruthy());
  });

  test("the wrap toggle switches the line wrapping", () => {
    const { container } = renderTree({ view: { type: "Code", code: "SELECT 1;", language: "sql" } });
    const pre = container.querySelector("pre");
    const wrappedClassName = () => pre?.className ?? "";
    const classNameBefore = wrappedClassName();
    fireEvent.click(screen.getByText("Wrap lines"));
    expect(container.querySelector("pre")?.className).not.toBe(classNameBefore);
  });

  test("long code collapses behind a toggle that shows every line", () => {
    const longCode = Array.from(
      { length: CODE_COLLAPSED_LINE_COUNT + 6 },
      (unused, lineIndex) => `line ${lineIndex}`,
    ).join("\n");
    renderTree({ view: { type: "Code", code: longCode } });
    const showAllLines = screen.getByText(`Show all ${CODE_COLLAPSED_LINE_COUNT + 6} lines`);
    fireEvent.click(showAllLines);
    expect(screen.getByText("Show fewer lines")).toBeTruthy();
  });

  test("an unknown language stays plain text with its written name", () => {
    const { container } = renderTree({ view: { type: "Code", code: "PRINT 'hi'", language: "COBOL-2026" } });
    expect(screen.getByText("COBOL-2026")).toBeTruthy();
    expect(container.querySelector(".hljs")).toBeNull();
  });

  test("the e-mail capture shows the code without controls", () => {
    renderTree({ view: { type: "Code", code: "SELECT 1;", language: "sql" } }, true);
    expect(screen.queryByText("Copy")).toBeNull();
    expect(screen.queryByText("Wrap lines")).toBeNull();
  });

  test("empty code reports empty", async () => {
    const { reports, container } = renderTree({ view: { type: "Code", code: "" } });
    expect(container.textContent).toBe("");
    await waitFor(() => expect(reports.at(-1)?.status).toBe("empty"));
  });

  test("code bound to a pending tool result shows the skeleton and reports pending", async () => {
    const { reports, container } = renderTree({
      view: {
        type: "Code",
        language: "php",
        code: { $from: { toolCall: "call_1", path: "$.code", state: "pending" } },
      },
    });
    expect(container.querySelector("[aria-busy='true']")).not.toBeNull();
    await waitFor(() => expect(reports.at(-1)?.status).toBe("pending"));
  });
});
