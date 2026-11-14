// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { fireEvent, screen } from "@testing-library/react";
import { PENDING_FROM_BINDING, renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Text — the plain text node of the ComposedView kit", () => {
  test("renders a paragraph with its inline children on the same line", () => {
    const { reports, container } = renderTextNodeTree({
      view: {
        type: "Text",
        text: "Quellen: WDR und Antenne Münster.",
        tone: "muted",
        children: [
          { type: "Badge", text: "Neu", tone: "info" },
          { type: "Link", text: "Artikel öffnen", url: "https://www.antenne-muenster.de/" },
        ],
      },
    });
    expect(screen.getByText("Quellen: WDR und Antenne Münster.")).toBeTruthy();
    expect(screen.getByText("Neu")).toBeTruthy();
    expect(screen.getByRole("link", { name: /Artikel öffnen/ }).getAttribute("href")).toBe(
      "https://www.antenne-muenster.de/",
    );
    expect(container.querySelector("div[class*='_paragraph']")).not.toBeNull();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("model text is shown as written, never parsed as markup", () => {
    renderTextNodeTree({ view: { type: "Text", text: "<b>fett</b> **nicht fett**" } });
    expect(screen.getByText("<b>fett</b> **nicht fett**")).toBeTruthy();
    expect(document.querySelector("b")).toBeNull();
  });

  test("a tag with a style attribute and an ampersand show as written and break nothing", () => {
    const { reports } = renderTextNodeTree({
      view: { type: "Text", text: 'A & B <span style="color:red">rot</span> <img src=x onerror=alert(1)>' },
    });
    expect(screen.getByText('A & B <span style="color:red">rot</span> <img src=x onerror=alert(1)>')).toBeTruthy();
    expect(document.querySelector("img")).toBeNull();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a number is text; a bound value follows the state", () => {
    renderTextNodeTree({ state: { seats: 94 }, view: { type: "Text", text: { $state: "seats" } } });
    expect(screen.getByText("94")).toBeTruthy();
  });

  test("a clamped text keeps its whole text on hover", () => {
    const longText = Array(6)
      .fill("Die Reaktivierung der Bahnstrecke Münster–Sendenhorst nimmt konkrete Formen an.")
      .join(" ");
    renderTextNodeTree({ view: { type: "Text", text: longText, maxLines: 2 } });
    expect(screen.getByTitle(longText)).toBeTruthy();
  });

  test("a clamp that cuts the text off offers Show more, which shows the whole text and turns into Show less", () => {
    const scrollHeightDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, "scrollHeight");
    Object.defineProperty(HTMLElement.prototype, "scrollHeight", { configurable: true, get: () => 120 });
    try {
      const longText = "Die Reaktivierung der Bahnstrecke Münster–Sendenhorst nimmt konkrete Formen an.";
      renderTextNodeTree({ view: { type: "Text", text: longText, maxLines: 2 } });
      const showMoreButton = screen.getByRole("button", { name: "Show more" });
      expect(showMoreButton.getAttribute("aria-expanded")).toBe("false");
      fireEvent.click(showMoreButton);
      expect(screen.queryByTitle(longText)).toBeNull();
      expect(screen.getByRole("button", { name: "Show less" }).getAttribute("aria-expanded")).toBe("true");
      fireEvent.click(screen.getByRole("button", { name: "Show less" }));
      expect(screen.getByTitle(longText)).toBeTruthy();
    } finally {
      // jsdom declares `scrollHeight` on `Element.prototype`; the override on `HTMLElement.prototype` is removed again.
      if (scrollHeightDescriptor) Object.defineProperty(HTMLElement.prototype, "scrollHeight", scrollHeightDescriptor);
      else delete (HTMLElement.prototype as { scrollHeight?: number }).scrollHeight;
    }
  });

  test("a clamp that cuts nothing off offers no Show more", () => {
    const { container } = renderTextNodeTree({ view: { type: "Text", text: "Kurz.", maxLines: 2 } });
    expect(container.querySelector("button")).toBeNull();
  });

  test("a value that is no text renders nothing and reports failed INVALID_PROP", () => {
    const { reports } = renderTextNodeTree({
      data: { rows: [{ a: 1 }] },
      view: { type: "Text", text: { $data: "rows" } },
    });
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("a Text without text and without children reports empty; pending shows a skeleton", () => {
    const empty = renderTextNodeTree({ view: { type: "Text" } });
    expect(empty.reports.at(-1)).toMatchObject({ status: "empty" });
    const pending = renderTextNodeTree({ view: { type: "Text", text: PENDING_FROM_BINDING } });
    expect(pending.reports.at(-1)).toEqual({ status: "pending" });
  });

  test("a block child is dropped by the reader; the run still renders", () => {
    const { reports } = renderTextNodeTree({
      view: { type: "Text", text: "Intro", children: [{ type: "Title", text: "kein Inline" }] },
    });
    expect(screen.getByText("Intro")).toBeTruthy();
    expect(screen.queryByText("kein Inline")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_NODE" });
  });
});
