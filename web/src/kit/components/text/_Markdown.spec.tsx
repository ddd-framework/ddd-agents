// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { PENDING_FROM_BINDING, renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Markdown — the safe formatted text node", () => {
  test("renders headings, emphasis, a list and a table", () => {
    const { reports, container } = renderTextNodeTree({
      view: {
        type: "Markdown",
        markdown:
          "## Rechnerische Koalitionsmöglichkeiten\n\nAuf Grundlage der **GMS-Umfrage**:\n\n- erste\n- zweite\n\n| Koalition | Sitze |\n|---|---:|\n| CSU + Grüne | 104 |",
      },
    });
    expect(screen.getByText("Rechnerische Koalitionsmöglichkeiten")).toBeTruthy();
    expect(container.querySelector("h2")).toBeNull();
    expect(container.querySelector("strong")?.textContent).toBe("GMS-Umfrage");
    expect(container.querySelectorAll("li")).toHaveLength(2);
    expect(screen.getByText("CSU + Grüne")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("raw HTML and images never reach the DOM", () => {
    const { container } = renderTextNodeTree({
      view: {
        type: "Markdown",
        markdown:
          'Vorher <script>alert(1)</script><b>fett</b> <img src="https://example.org/x.png"> ![pixel](https://example.org/p.png) nachher',
      },
    });
    expect(container.querySelector("script")).toBeNull();
    expect(container.querySelector("b")).toBeNull();
    expect(container.querySelector("img")).toBeNull();
    expect(container.textContent).toContain("Vorher");
    expect(container.textContent).toContain("nachher");
  });

  test("links open http(s) pages in a new tab; another scheme keeps only its text", () => {
    const { container } = renderTextNodeTree({
      view: {
        type: "Markdown",
        markdown: "[WDR](https://www1.wdr.de) und [böse](javascript:alert(1)) und [Mail](mailto:a@b.de)",
      },
    });
    const links = Array.from(container.querySelectorAll("a"));
    expect(links).toHaveLength(1);
    expect(links[0].getAttribute("href")).toBe("https://www1.wdr.de/");
    expect(links[0].getAttribute("target")).toBe("_blank");
    expect(links[0].getAttribute("rel")).toBe("noopener noreferrer");
    expect(container.textContent).toContain("böse");
    expect(container.textContent).toContain("Mail");
  });

  test("pending and empty are reported", () => {
    const pending = renderTextNodeTree({
      view: { type: "Markdown", markdown: PENDING_FROM_BINDING },
    });
    expect(pending.reports.at(-1)).toEqual({ status: "pending" });
    const empty = renderTextNodeTree({
      state: { text: " " },
      view: { type: "Markdown", markdown: { $state: "text" } },
    });
    expect(empty.reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
