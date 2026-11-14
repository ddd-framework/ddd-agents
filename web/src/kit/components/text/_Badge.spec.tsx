// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Badge — the status chip node", () => {
  test("renders the status with its tone and reports mounted", () => {
    const { reports, container } = renderTextNodeTree({ view: { type: "Badge", text: "Mehrheit", tone: "success" } });
    expect(screen.getByText("Mehrheit")).toBeTruthy();
    expect(container.querySelector("[data-tone='success']")).not.toBeNull();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a status with markup shows as written", () => {
    renderTextNodeTree({ view: { type: "Badge", text: "<b>Live</b> & mehr" } });
    expect(screen.getByText("<b>Live</b> & mehr")).toBeTruthy();
    expect(document.querySelector("b")).toBeNull();
  });

  test("a bound tone outside the list falls back to neutral", () => {
    const { container } = renderTextNodeTree({
      state: { tone: "purple" },
      view: { type: "Badge", text: "Live", tone: { $state: "tone" } },
    });
    expect(container.querySelector("[data-tone='neutral']")).not.toBeNull();
  });

  test("an empty status renders nothing and reports empty", () => {
    const { reports, container } = renderTextNodeTree({
      state: { status: "" },
      view: { type: "Badge", text: { $state: "status" } },
    });
    expect(container.querySelector("[data-tone]")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
