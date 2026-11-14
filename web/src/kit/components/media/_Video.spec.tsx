// @vitest-environment jsdom
import { afterEach, describe, expect, test, vi } from "vitest";
import { fireEvent, screen } from "@testing-library/react";
import { formatVideoDuration } from "./media-values";
import { renderMediaTree } from "./media-spec-render";

/**
 * The `Video` node through the renderer: the card with title, channel · length and
 * the watch link, a connector video that plays in place, a plain url that opens a new tab, the thumbnail derived from
 * the id, the e-mail capture without play button and link, and a video that can be neither played nor opened reported.
 */
const MUENSTER_TOUR = {
  type: "Video",
  title: "Münster, Germany 4k Walking Tour",
  videoId: "x5-yoDUoz5k",
  durationSeconds: 1418,
  channel: "Duslin Travels",
  source: { name: "YouTube", url: "https://www.youtube.com" },
};

afterEach(() => {
  vi.restoreAllMocks();
});

describe("the Video node", () => {
  test("shows the title, channel and length, the derived thumbnail and the watch link, and reports mounted", () => {
    const { container, lastReport } = renderMediaTree({ view: MUENSTER_TOUR });

    expect(screen.getByText("Münster, Germany 4k Walking Tour")).toBeTruthy();
    expect(screen.getByText("Duslin Travels · 23:38")).toBeTruthy();
    expect(screen.getAllByText("23:38").length).toBeGreaterThan(0);
    expect(screen.getByText("YouTube")).toBeTruthy();
    expect(container.querySelector("img")?.getAttribute("src")).toBe(
      "https://i.ytimg.com/vi/x5-yoDUoz5k/hqdefault.jpg",
    );
    const watchLink = screen.getByRole("link");
    expect(watchLink.getAttribute("href")).toBe("https://www.youtube.com/watch?v=x5-yoDUoz5k");
    expect(watchLink.getAttribute("target")).toBe("_blank");
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("a connector video plays in place", () => {
    const { container } = renderMediaTree({ view: MUENSTER_TOUR });

    fireEvent.click(screen.getByRole("button", { name: "Play the video Münster, Germany 4k Walking Tour" }));

    const player = container.querySelector("iframe");
    expect(player?.getAttribute("src")).toBe("https://www.youtube-nocookie.com/embed/x5-yoDUoz5k?autoplay=1&rel=0");
    expect(player?.getAttribute("title")).toBe(MUENSTER_TOUR.title);
  });

  test("a video with a plain url opens it in a new tab", () => {
    const openWindow = vi.spyOn(window, "open").mockReturnValue(null);
    const { container } = renderMediaTree({
      view: { type: "Video", title: "A city walk", url: "https://vimeo.com/123456" },
    });

    fireEvent.click(screen.getByRole("button"));

    expect(openWindow).toHaveBeenCalledWith("https://vimeo.com/123456", "_blank", "noopener,noreferrer");
    expect(container.querySelector("iframe")).toBeNull();
  });

  test("a YouTube watch link written as url plays in place like a connector video", () => {
    const { container } = renderMediaTree({
      view: { type: "Video", title: "A city walk", url: "https://youtu.be/x5-yoDUoz5k" },
    });

    fireEvent.click(screen.getByRole("button", { name: "Play the video A city walk" }));

    expect(container.querySelector("iframe")?.getAttribute("src")).toBe(
      "https://www.youtube-nocookie.com/embed/x5-yoDUoz5k?autoplay=1&rel=0",
    );
  });

  test("a thumbnail that does not load leaves the play glyph on the empty frame", () => {
    const { container } = renderMediaTree({ view: { ...MUENSTER_TOUR, thumbnail: "https://example.org/missing.jpg" } });

    fireEvent.error(container.querySelector("img") as HTMLImageElement);

    expect(container.querySelector("img")).toBeNull();
    expect(screen.getByRole("button")).toBeTruthy();
  });

  test("the e-mail capture shows the card without play button and link", () => {
    renderMediaTree({ view: MUENSTER_TOUR }, { isEmailRender: true });

    expect(screen.getByText("Münster, Germany 4k Walking Tour")).toBeTruthy();
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.queryByRole("link")).toBeNull();
  });

  test("a video with neither a videoId nor an http(s) url shows its text and reports failed", () => {
    const { lastReport } = renderMediaTree({
      view: { type: "Video", title: "A tour", videoId: "bad id!", url: "ftp://example.org/v" },
    });

    expect(screen.getByText("A tour")).toBeTruthy();
    expect(screen.queryByRole("button")).toBeNull();
    expect(lastReport()).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("the length reads as minutes or hours", () => {
    expect(formatVideoDuration(1418)).toBe("23:38");
    expect(formatVideoDuration(3725)).toBe("1:02:05");
    expect(formatVideoDuration(-1)).toBeUndefined();
    expect(formatVideoDuration("23:38")).toBeUndefined();
  });
});
