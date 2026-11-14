import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../../../provider/environment";
import { DataFootnote } from "./DataFootnote";

const sources = [
  { name: "Google profile", url: "https://example.com/profile" },
  { name: "Reviews", url: "http://example.com/reviews" },
];

describe("DataFootnote", () => {
  // The footnote with the package's default components: a host's button and icon render their own markup.
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({});
  });

  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  test("renders nothing without a caption or source", () => {
    const { container } = render(<DataFootnote isEmailRender={false} appearance="quiet" />);
    expect(container).toBeEmptyDOMElement();
  });

  test.each(["chip", "quiet"] as const)("keeps safe links and ordered source ids in %s appearance", (appearance) => {
    render(
      <DataFootnote
        source={sources}
        caption="Updated today"
        isEmailRender={false}
        appearance={appearance}
        testId="sources"
      />,
    );
    expect(screen.getByText("Updated today")).toBeInTheDocument();
    sources.forEach((source, index) => {
      const control = screen.getByRole(appearance === "chip" ? "link" : "button", { name: source.name });
      expect(screen.getByTestId(`sources-source-${index}`)).toContainElement(control);
      if (appearance === "chip") {
        expect(control).toHaveAttribute("href", source.url);
        expect(control).toHaveAttribute("target", "_blank");
        expect(control).toHaveAttribute("rel", "noopener noreferrer");
        expect(control.className).toContain("sourceChip");
      } else {
        expect(control).toHaveAttribute("type", "button");
        expect(control.className).toContain("agents-button--default");
        expect(control.className).toContain("agents-button--small");
        expect(control.firstElementChild).toHaveAttribute("data-icon", "link");
      }
    });
  });

  test("opens a KPI source in a new tab with opener protection", () => {
    const open = vi.spyOn(window, "open").mockImplementation(() => null);
    try {
      render(<DataFootnote source={sources[0]} appearance="quiet" isEmailRender={false} testId="sources" />);
      fireEvent.click(screen.getByRole("button", { name: sources[0].name }));
      expect(open).toHaveBeenCalledExactlyOnceWith(sources[0].url, "_blank", "noopener,noreferrer");
    } finally {
      open.mockRestore();
    }
  });

  test("keeps legacy chips by default", () => {
    render(<DataFootnote source={{ name: "getGoogleProfile" }} isEmailRender={false} testId="sources" />);
    expect(screen.getByTestId("sources-source-0").className).toContain("sourceChip");
  });

  test.each(["javascript:alert(1)", "data:text/html,test", "/local", "mailto:info@example.com"])(
    "keeps chip source %s as noninteractive text",
    (url) => {
      render(<DataFootnote source={{ name: "Source", url }} isEmailRender={false} />);
      expect(screen.getByText("Source")).toBeInTheDocument();
      expect(screen.queryByRole("link")).toBeNull();
    },
  );

  test("omits a name-only KPI source and its entire footer", () => {
    const name = "getGoogleBusinessProfileVerificationAndPublicationStatusForAllLocations <b>& status</b>";
    const { container } = render(
      <DataFootnote source={{ name }} isEmailRender={false} appearance="quiet" testId="sources" />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  test.each([
    "javascript:alert(1)",
    "data:text/html,test",
    "/local",
    "mailto:info@example.com",
    "https://",
    "http://bad host",
  ])("omits a KPI source with invalid URL %s", (url) => {
    const { container } = render(
      <DataFootnote source={{ name: "Source", url }} appearance="quiet" isEmailRender={false} />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  test("omits KPI sources in email render", () => {
    const { container } = render(<DataFootnote source={sources} appearance="quiet" isEmailRender />);
    expect(container).toBeEmptyDOMElement();
  });

  test("keeps chip email sources as text with no links", () => {
    render(<DataFootnote source={sources} isEmailRender />);
    expect(screen.getByText("Google profile")).toBeInTheDocument();
    expect(screen.getByText("Reviews")).toBeInTheDocument();
    expect(screen.queryByRole("link")).toBeNull();
  });

  test("keeps a caption when the KPI source is omitted", () => {
    render(
      <DataFootnote
        caption="Updated today"
        source={{ name: "getRankings" }}
        appearance="quiet"
        isEmailRender={false}
      />,
    );
    expect(screen.getByText("Updated today")).toBeInTheDocument();
    expect(screen.queryByText("getRankings")).toBeNull();
  });

  test("uses the host name when a KPI URL has no name", () => {
    render(<DataFootnote source={{ url: "https://example.com/rankings" }} appearance="quiet" isEmailRender={false} />);
    expect(screen.getByRole("button", { name: "example.com" })).toBeInTheDocument();
  });

  test("keeps source ids when a preceding KPI source is omitted and shows the linked label as written", () => {
    const name = "Rankings <b>& report</b>";
    render(
      <DataFootnote
        source={[{ name: "getRankings" }, { name, url: "https://example.com" }]}
        appearance="quiet"
        isEmailRender={false}
        testId="sources"
      />,
    );
    const link = screen.getByRole("button", { name });
    expect(screen.getByTestId("sources-source-1")).toContainElement(link);
    expect(link.querySelector("b")).toBeNull();
    expect(link.textContent).toBe(name);
    const icon = link.querySelector('[data-icon="link"]');
    expect(icon).not.toBeNull();
    expect(link.firstChild).toBe(icon);
    expect(screen.queryByTestId("sources-source-0")).toBeNull();
  });
});
