// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { fireEvent, screen } from "@testing-library/react";
import { renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Favicon — the site icon node", () => {
  test("shows the icon of the site of a page url or a bare domain", () => {
    const { reports, container } = renderTextNodeTree({
      view: { type: "Favicon", url: "https://www.wdr.de/nachrichten", name: "WDR" },
    });
    const image = container.querySelector("img");
    expect(image?.getAttribute("src")).toBe("https://www.google.com/s2/favicons?domain=wdr.de&sz=32");
    expect(image?.getAttribute("alt")).toBe("WDR");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
    const bareDomain = renderTextNodeTree({ view: { type: "Favicon", url: "antenne-muenster.de" } });
    expect(bareDomain.container.querySelector("img")?.getAttribute("src")).toContain("domain=antenne-muenster.de");
  });

  test("a failed image load shows the link glyph", () => {
    const { container } = renderTextNodeTree({ view: { type: "Favicon", url: "wdr.de", name: "WDR" } });
    fireEvent.error(container.querySelector("img") as HTMLImageElement);
    expect(container.querySelector("img")).toBeNull();
    expect(screen.getByRole("img", { name: "WDR" })).toBeTruthy();
  });

  test("the e-mail capture never requests an outside image", () => {
    const { container } = renderTextNodeTree(
      { view: { type: "Favicon", url: "wdr.de", name: "WDR" } },
      { isEmailRender: true },
    );
    expect(container.querySelector("img")).toBeNull();
  });

  test("a url that names no site shows the glyph and reports failed INVALID_PROP", () => {
    const { container, reports } = renderTextNodeTree({ view: { type: "Favicon", url: "javascript:alert(1)" } });
    expect(container.querySelector("img")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });
});
