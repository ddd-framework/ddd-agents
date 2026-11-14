// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { act, fireEvent, screen } from "@testing-library/react";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { PENDING_FROM_BINDING, renderTextNodeTree } from "./shared/render-text-node-tree";

const NEWS_SOURCES = [
  { name: "WDR", url: "https://www1.wdr.de/nachrichten/westfalen-lippe/bahnstrecke-muenster-sendenhorst-100.html" },
  { name: "Antenne Münster", url: "https://www.antenne-muenster.de/artikel/sturm" },
  { name: "Westfälische Nachrichten" },
];

describe("SourceRef — the source line node", () => {
  test("lists every source with its favicon and a link to its page", () => {
    const { reports, container } = renderTextNodeTree({ view: { type: "SourceRef", sources: NEWS_SOURCES } });
    expect(screen.getByText("Source")).toBeTruthy();
    expect(screen.getAllByRole("listitem")).toHaveLength(3);
    const wdrLink = screen.getByRole("link", { name: /WDR/ });
    expect(wdrLink.getAttribute("href")).toBe(NEWS_SOURCES[0].url);
    expect(wdrLink.getAttribute("target")).toBe("_blank");
    expect(screen.getByText("Westfälische Nachrichten").closest("a")).toBeNull();
    expect(container.querySelectorAll("img")).toHaveLength(2);
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a licensed source gets its attribution line: title · author · licence · source", () => {
    renderTextNodeTree({
      view: {
        type: "SourceRef",
        sources: [
          {
            name: "Wikimedia Commons",
            url: "https://commons.wikimedia.org/wiki/File:Koeln_Dom.jpg",
            title: "Kölner Dom",
            author: "Raimond Spekking",
            license: "CC BY-SA 4.0",
            licenseUrl: "https://creativecommons.org/licenses/by-sa/4.0/",
          },
        ],
      },
    });
    const licenseLink = screen.getByRole("link", { name: "CC BY-SA 4.0" });
    expect(licenseLink.getAttribute("href")).toBe("https://creativecommons.org/licenses/by-sa/4.0/");
    expect(licenseLink.getAttribute("target")).toBe("_blank");
    const attributionLine = licenseLink.parentElement as HTMLElement;
    expect(Array.from(attributionLine.children).map((part) => part.textContent)).toEqual([
      "Kölner Dom",
      "·",
      "Raimond Spekking",
      "·",
      "CC BY-SA 4.0",
      "·",
      "Wikimedia Commons",
    ]);
  });

  test("in a compact slot it is one chip that unfolds the full list", () => {
    renderTextNodeTree({
      view: {
        type: "Text",
        text: "Quellen: WDR und Antenne Münster.",
        children: [{ type: "SourceRef", sources: NEWS_SOURCES }],
      },
    });
    const chip = screen.getByRole("button", { name: /WDR/ });
    expect(chip.getAttribute("aria-expanded")).toBe("false");
    expect(chip.textContent).toContain("+2");
    expect(screen.queryByRole("listitem")).toBeNull();
    act(() => {
      fireEvent.click(chip);
    });
    expect(chip.getAttribute("aria-expanded")).toBe("true");
    expect(screen.getAllByRole("listitem")).toHaveLength(3);
  });

  test("unsafe urls are shown without a link; sources without a name are left out and reported", () => {
    const { reports } = renderTextNodeTree({
      view: {
        type: "SourceRef",
        sources: [
          { name: "Böse", url: "javascript:alert(1)", licenseUrl: "data:x", license: "frei" },
          { url: "https://x.de" },
          "WDR",
        ],
      },
    });
    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.getAllByRole("listitem")).toHaveLength(1);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("more sources than maxRepeatRows are cut and reported", () => {
    const maxRepeatRows = composedNodeSchema.budgets.maxRepeatRows;
    const manySources = Array.from({ length: maxRepeatRows + 3 }, (_, sourceIndex) => ({
      name: `Quelle ${sourceIndex}`,
    }));
    const { reports } = renderTextNodeTree({ view: { type: "SourceRef", sources: manySources } });
    expect(screen.getAllByRole("listitem")).toHaveLength(maxRepeatRows);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "BUDGET_EXCEEDED" });
  });

  test("pending, empty and a non-list are reported", () => {
    const pending = renderTextNodeTree({
      view: { type: "SourceRef", sources: PENDING_FROM_BINDING },
    });
    expect(pending.reports.at(-1)).toEqual({ status: "pending" });
    const empty = renderTextNodeTree({ view: { type: "SourceRef", sources: [] } });
    expect(empty.reports.at(-1)).toMatchObject({ status: "empty" });
    const notAList = renderTextNodeTree({
      state: { name: "WDR" },
      view: { type: "SourceRef", sources: { $state: "name" } },
    });
    expect(notAList.reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });
});
