// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";

/** `Quote` through the renderer: the quoted words, who and where, the e-mail form, the empty report. */
describe("Quote", () => {
  const view = {
    type: "Quote",
    text: "Best coffee in Münster.",
    attribution: "Anna K.",
    source: { name: "Google reviews", url: "https://maps.google.com/?cid=1" },
  };

  test("shows the words, the attribution and the source as a new-tab link", () => {
    const { reports } = renderLayoutTree({ view });
    expect(screen.getByText("Best coffee in Münster.").closest("blockquote")?.getAttribute("cite")).toBe(
      "https://maps.google.com/?cid=1",
    );
    expect(screen.getByText("Anna K.")).toBeTruthy();
    const sourceLink = screen.getByRole("link", { name: "Google reviews" });
    expect(sourceLink.getAttribute("target")).toBe("_blank");
    expect(sourceLink.getAttribute("rel")).toBe("noopener noreferrer");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("the quoted words keep the model's markup as text", () => {
    renderLayoutTree({ view: { type: "Quote", text: "<em>Great</em> & cheap", attribution: "<script>x</script>" } });
    expect(screen.getByText("<em>Great</em> & cheap")).toBeTruthy();
    expect(screen.getByText("<script>x</script>")).toBeTruthy();
  });

  test("the e-mail capture names the source without a link", () => {
    renderLayoutTree({ view }, true);
    expect(screen.getByText("Google reviews")).toBeTruthy();
    expect(screen.queryByRole("link")).toBeNull();
  });

  test("a source that is not http(s) is shown by name only", () => {
    renderLayoutTree({ view: { type: "Quote", text: "Hi", source: { name: "Archive", url: "javascript:alert(1)" } } });
    expect(screen.getByText("Archive")).toBeTruthy();
    expect(screen.queryByRole("link")).toBeNull();
  });

  test("a quote without words renders nothing and reports empty", () => {
    const { reports } = renderLayoutTree({ view: { type: "Quote", attribution: "Nobody" } });
    expect(screen.queryByText("Nobody")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
