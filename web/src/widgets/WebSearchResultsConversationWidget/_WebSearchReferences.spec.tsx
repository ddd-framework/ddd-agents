import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { WebSearchReferences } from "./WebSearchReferences";

describe("WebSearchReferences", () => {
  it("labels a chip by its site, and by its page title where several pages of one site are listed", () => {
    render(
      <WebSearchReferences
        sources={[
          { url: "https://www.example-polls.de/umfragen/kw-43", title: "Umfrage-Woche KW 43" },
          { url: "https://www.example-polls.de/umfragen/deutschland", title: "Umfragen in Deutschland" },
          { url: "https://www.bundeswahlleiterin.de/ergebnisse", title: "Ergebnisse" },
        ]}
      />,
    );
    expect(screen.getByText("Umfrage-Woche KW 43")).toBeTruthy();
    expect(screen.getByText("Umfragen in Deutschland")).toBeTruthy();
    expect(screen.getByText("bundeswahlleiterin.de")).toBeTruthy();
    expect(screen.queryByText("example-polls.de")).toBeNull();
  });
});
