// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Link — the safe text link node", () => {
  test("opens an http(s) page in a new tab and reports mounted", () => {
    const { reports } = renderTextNodeTree({
      view: { type: "Link", text: "Artikel öffnen", url: "https://www.antenne-muenster.de/artikel" },
    });
    const link = screen.getByRole("link", { name: /Artikel öffnen/ });
    expect(link.getAttribute("href")).toBe("https://www.antenne-muenster.de/artikel");
    expect(link.getAttribute("target")).toBe("_blank");
    expect(link.getAttribute("rel")).toBe("noopener noreferrer");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test.each(["javascript:alert(1)", "data:text/html,<b>x</b>", "mailto:a@b.de", "/relative/path", "nicht eine url"])(
    "a %s url is no link: the text shows alone and the node reports failed INVALID_PROP",
    (unsafeUrl) => {
      const { reports } = renderTextNodeTree({ view: { type: "Link", text: "Mehr", url: unsafeUrl } });
      expect(screen.queryByRole("link")).toBeNull();
      expect(screen.getByText("Mehr")).toBeTruthy();
      expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
    },
  );

  test("a link without url is dropped by the reader", () => {
    const { findingCodes, reports } = renderTextNodeTree({ view: { type: "Link", text: "Mehr" } });
    expect(findingCodes).toEqual(["INVALID_NODE"]);
    expect(reports.at(-1)).toMatchObject({ status: "failed" });
  });
});
