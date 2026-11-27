// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { cleanup, fireEvent, screen } from "@testing-library/react";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../../../provider/environment";
import { renderMediaTree } from "../media-spec-render";
import { formatPublishedDay, formatViewCount, readViewCount } from "./video-widget-values";
/**
 * The `VideoWidget` node (one YouTube search result) through the renderer: thumbnail with the length
 * chip, title, channel · views · date and the watch link; play in place; a grid of cards; the thumbnail from the id;
 * the e-mail capture without controls; the pending skeleton; a card without a playable id reported failed.
 */
const MUENSTER_TOUR = {
    type: "VideoWidget",
    title: "Münster, Germany 4k Walking Tour",
    videoId: "x5-yoDUoz5k",
    thumbnail: "https://i.ytimg.com/vi/x5-yoDUoz5k/hqdefault.jpg",
    durationSeconds: 1418,
    channel: "Duslin Travels",
    viewCount: 18234,
    publishedAt: "2025-02-08",
};
/** The package's schema declares no `VideoWidget` node type: an app declares it in its part, as this example does. */
const bindableProp = (name, type, isRequired = false) => ({
    name,
    types: [type],
    isRequired,
    isBindable: true,
    allowedValues: null,
    description: "",
});
const VIDEO_WIDGET_PROPS = [
    bindableProp("title", "string", true),
    bindableProp("videoId", "string", true),
    bindableProp("thumbnail", "string"),
    bindableProp("durationSeconds", "number"),
    bindableProp("channel", "string"),
    bindableProp("viewCount", "number"),
    bindableProp("publishedAt", "string"),
];
/** `VideoCard` is the node type's name before 0.1.0-alpha.59, still declared by an app that has not renamed it. */
const VIDEO_WIDGET_APPLICATION_PART = {
    nodeTypes: {
        VideoCard: {
            categories: ["media"],
            providesItemScope: false,
            summary: "one video as a card",
            props: VIDEO_WIDGET_PROPS,
            slots: [],
        },
        VideoWidget: {
            categories: ["media"],
            providesItemScope: false,
            summary: "one video as a card",
            props: [
                bindableProp("title", "string", true),
                bindableProp("videoId", "string", true),
                bindableProp("thumbnail", "string"),
                bindableProp("durationSeconds", "number"),
                bindableProp("channel", "string"),
                bindableProp("viewCount", "number"),
                bindableProp("publishedAt", "string"),
            ],
            slots: [],
        },
    },
};
let environmentBefore;
beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({ ...environmentBefore, composedNodeSchemaApplicationPart: VIDEO_WIDGET_APPLICATION_PART });
});
afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
    vi.restoreAllMocks();
});
describe("the VideoWidget node", () => {
    test("the legacy node type VideoCard renders as a VideoWidget", () => {
        const { lastReport } = renderMediaTree({ view: { ...MUENSTER_TOUR, type: "VideoCard" } });
        expect(screen.getByText("Münster, Germany 4k Walking Tour")).toBeTruthy();
        expect(lastReport()).toEqual({ status: "mounted" });
    });
    test("shows thumbnail, length, title, channel, views and date with the watch link, and reports mounted", () => {
        const { container, lastReport } = renderMediaTree({ view: MUENSTER_TOUR });
        expect(screen.getByText("Münster, Germany 4k Walking Tour")).toBeTruthy();
        expect(screen.getByText("23:38")).toBeTruthy();
        expect(screen.getByText("Duslin Travels")).toBeTruthy();
        expect(screen.getByText(/18.2K view/)).toBeTruthy();
        expect(screen.getByText("Feb 8, 2025")).toBeTruthy();
        expect(container.querySelector("img")?.getAttribute("src")).toBe(MUENSTER_TOUR.thumbnail);
        const watchLink = screen.getByRole("link");
        expect(watchLink.getAttribute("href")).toBe("https://www.youtube.com/watch?v=x5-yoDUoz5k");
        expect(watchLink.getAttribute("target")).toBe("_blank");
        expect(lastReport()).toEqual({ status: "mounted" });
    });
    test("play swaps the thumbnail for the embed in place", () => {
        const { container } = renderMediaTree({ view: MUENSTER_TOUR });
        fireEvent.click(screen.getByRole("button", { name: "Play the video Münster, Germany 4k Walking Tour" }));
        const player = container.querySelector("iframe");
        expect(player?.getAttribute("src")).toBe("https://www.youtube-nocookie.com/embed/x5-yoDUoz5k?autoplay=1&rel=0");
        expect(container.querySelector("img")).toBeNull();
    });
    test("a grid of cards renders every card, each with its own player", () => {
        const { container, lastReport } = renderMediaTree({
            view: {
                type: "Grid",
                columns: 2,
                minColumnWidth: "regular",
                children: [MUENSTER_TOUR, { type: "VideoWidget", title: "Prinzipalmarkt at night", videoId: "QZRHqiz_oH4" }],
            },
        });
        expect(container.querySelectorAll("article")).toHaveLength(2);
        fireEvent.click(screen.getByRole("button", { name: "Play the video Prinzipalmarkt at night" }));
        expect(container.querySelectorAll("iframe")).toHaveLength(1);
        expect(container.querySelector("iframe")?.getAttribute("src")).toContain("/embed/QZRHqiz_oH4");
        expect(lastReport()).toEqual({ status: "mounted" });
    });
    test("without a thumbnail and details the card takes the id's thumbnail and shows no length or views", () => {
        const { container } = renderMediaTree({ view: { type: "VideoWidget", title: "A tour", videoId: "x5-yoDUoz5k" } });
        expect(container.querySelector("img")?.getAttribute("src")).toBe("https://i.ytimg.com/vi/x5-yoDUoz5k/hqdefault.jpg");
        expect(screen.queryByText(/view/)).toBeNull();
        expect(screen.queryByText("23:38")).toBeNull();
    });
    test("the e-mail capture shows the card without play button and link", () => {
        renderMediaTree({ view: MUENSTER_TOUR }, { isEmailRender: true });
        expect(screen.getByText("Münster, Germany 4k Walking Tour")).toBeTruthy();
        expect(screen.queryByRole("button")).toBeNull();
        expect(screen.queryByRole("link")).toBeNull();
    });
    test("a pending videoId renders the card-shaped skeleton", () => {
        const { container } = renderMediaTree({
            view: {
                type: "VideoWidget",
                title: "Pending",
                videoId: { $from: { toolCall: "call_1", path: "$.videos[0].videoId", state: "pending" } },
            },
        });
        expect(container.querySelector('[aria-busy="true"]')).not.toBeNull();
        expect(screen.queryByText("Pending")).toBeNull();
    });
    test("a card whose videoId is no YouTube id shows its title and reports failed", () => {
        const { container, lastReport } = renderMediaTree({
            view: { type: "VideoWidget", title: "A tour", videoId: "bad id!" },
        });
        expect(screen.getByText("A tour")).toBeTruthy();
        expect(screen.queryByRole("button")).toBeNull();
        expect(container.querySelector("img")).toBeNull();
        expect(lastReport()).toMatchObject({ status: "failed", code: "INVALID_PROP" });
    });
    test("a model-written title renders as text, never as markup", () => {
        const { container } = renderMediaTree({
            view: { ...MUENSTER_TOUR, title: '<b onmouseover="x">Tour</b>' },
        });
        expect(container.querySelector("b")).toBeNull();
        expect(screen.getByText('<b onmouseover="x">Tour</b>')).toBeTruthy();
    });
    test("views and days read in the locale, bad values not at all", () => {
        expect(formatViewCount(18234, "en")).toBe("18.2K");
        expect(formatViewCount(1284000, "en")).toBe("1.3M");
        expect(formatPublishedDay("2025-02-08", "en")).toBe("Feb 8, 2025");
        expect(formatPublishedDay("2025-02-30", "en")).toBeUndefined();
        expect(formatPublishedDay("8.2.2025", "en")).toBeUndefined();
        expect(readViewCount(-1)).toBeUndefined();
        expect(readViewCount("18K")).toBeUndefined();
    });
});
