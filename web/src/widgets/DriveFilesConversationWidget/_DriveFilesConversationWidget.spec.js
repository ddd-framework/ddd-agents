// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { registerCanvasKind, unregisterCanvasKind } from "../../renderer/canvas/canvas-kinds";
import { canvasInstanceIdForReference } from "../../renderer/canvas/canvas-state";
import { ConversationWidgetContext, defaultConversationWidgetCallbacks } from "../../renderer/ConversationWidgetCallbacksContext";
import { createAgentsWidgetHydrationStateStore } from "../../renderer/widget-hydration/widget-hydration-state-store";
import { DriveFilesConversationWidget } from "./DriveFilesConversationWidget";
import { driveFileCanvasTargetOf, registerDriveFileCanvasOpener, unregisterDriveFileCanvasOpener } from "./shared/drive-file-canvas-openers";
import { registerDriveFileCardsSource, registerDriveFilesHydrator, unregisterDriveFileCardsSource, } from "./shared/drive-file-cards-source";
const PAYLOAD = JSON.stringify({ store: "drive", references: ["drive/812", "drive/813", "drive/40"], title: "Deck" });
const CARDS = [
    { reference: "drive/812", name: "deck.pptx", path: "out/deck.pptx", sizeBytes: 2048, urlsExpireAt: "2099-01-01 00:00:00", openLink: "/drive?space=1&file=812" },
    { reference: "drive/813", name: "deck.pdf", path: "out/deck.pdf", contentType: "application/pdf", urlsExpireAt: "2099-01-01 00:00:00" },
    { reference: "drive/40", name: "web", path: "web", isFolder: true, childCount: 3, urlsExpireAt: "2099-01-01 00:00:00", openLink: "https://evil.example/x" },
];
const buildSource = (cards) => ({
    readFileCards: vi.fn(() => Promise.resolve(cards)),
    readFileContent: vi.fn((card) => Promise.resolve({ url: `https://store.example/${card.reference}` })),
    buildDriveScreenHref: (card) => `/drive?node=${card.reference}`,
});
describe("DriveFilesConversationWidget", () => {
    let environmentBefore;
    beforeEach(() => {
        environmentBefore = getAgentsEnvironment();
        setAgentsEnvironment({ stateStore: createAgentsWidgetHydrationStateStore() });
        registerDriveFilesHydrator();
    });
    afterEach(() => {
        cleanup();
        unregisterDriveFileCardsSource();
        unregisterDriveFileCanvasOpener("drive");
        unregisterCanvasKind("exampleDriveFile");
        setAgentsEnvironment(environmentBefore);
    });
    it("says the drive is not connected while no source is registered", async () => {
        render(<DriveFilesConversationWidget payload={PAYLOAD}/>);
        await waitFor(() => expect(screen.getByText("The drive is not connected yet")).toBeTruthy());
    });
    it("renders nothing for a block without references", () => {
        const { container } = render(<DriveFilesConversationWidget payload={JSON.stringify({ store: "drive", references: [] })}/>);
        expect(container.innerHTML).toBe("");
    });
    it("lists files and folders: the pptx opens its PDF twin, the folder has no open or download", async () => {
        const source = buildSource(CARDS);
        registerDriveFileCardsSource(source);
        render(<DriveFilesConversationWidget payload={PAYLOAD}/>);
        await waitFor(() => expect(screen.getAllByTestId("drive-files-widget-card")).toHaveLength(3));
        expect(screen.getByText("Deck")).toBeTruthy();
        expect(screen.getAllByTestId("drive-files-widget-open")).toHaveLength(2);
        expect(screen.getAllByTestId("drive-files-widget-download")).toHaveLength(2);
        // the pptx's own link, the pdf's built link, the folder's foreign link replaced by the built one
        expect(screen.getAllByTestId("drive-files-widget-show-in-drive")).toHaveLength(3);
        const tab = { opener: {}, location: { href: "" }, close: vi.fn() };
        const openSpy = vi.spyOn(window, "open").mockReturnValue(tab);
        await act(async () => {
            fireEvent.click(screen.getAllByTestId("drive-files-widget-open")[0]);
        });
        await waitFor(() => expect(tab.location.href).toBe("https://store.example/drive/813"));
        expect(source.readFileContent).toHaveBeenCalledWith(expect.objectContaining({ reference: "drive/813" }), "inline");
        openSpy.mockRestore();
    });
    it("shows no file when none is readable", async () => {
        registerDriveFileCardsSource(buildSource([]));
        render(<DriveFilesConversationWidget payload={PAYLOAD}/>);
        await waitFor(() => expect(screen.getByText("No file to show")).toBeTruthy());
    });
    /** A store's opener: files open the example canvas by their reference, folders have none. */
    const registerExampleOpener = () => {
        registerCanvasKind({
            canvasId: "exampleDriveFile",
            behaviour: { lifetime: "pinned", layout: "split", narrowViewport: "overlay", fullBleed: false },
            body: () => null,
            openedByWidgets: ["DriveFiles"],
        });
        registerDriveFileCanvasOpener("drive", (card) => card.isFolder
            ? null
            : { canvasId: "exampleDriveFile", instanceId: canvasInstanceIdForReference("exampleDriveFile", card.reference) });
    };
    it("opens a file in the store's canvas when the store registered an opener; the folder keeps its actions", async () => {
        const source = buildSource(CARDS);
        registerDriveFileCardsSource(source);
        registerExampleOpener();
        const openCanvas = vi.fn();
        const openSpy = vi.spyOn(window, "open");
        render(<ConversationWidgetContext.Provider value={{ ...defaultConversationWidgetCallbacks, hostHasCanvasSurface: true, openCanvas }}>
        <DriveFilesConversationWidget payload={PAYLOAD} messageId={41}/>
      </ConversationWidgetContext.Provider>);
        await waitFor(() => expect(screen.getAllByTestId("drive-files-widget-card")).toHaveLength(3));
        // both files open the canvas (the pptx itself, not its PDF twin in a tab); the folder has no open
        expect(screen.getAllByTestId("drive-files-widget-open-canvas")).toHaveLength(2);
        expect(screen.queryAllByTestId("drive-files-widget-open")).toHaveLength(0);
        expect(screen.getAllByTestId("drive-files-widget-download")).toHaveLength(2);
        fireEvent.click(screen.getAllByTestId("drive-files-widget-open-canvas")[1]);
        expect(openCanvas).toHaveBeenCalledWith({
            canvasId: "exampleDriveFile",
            instanceId: "exampleDriveFile:reference:drive/813",
            anchor: { kind: "message", messageId: 41 },
            origin: "widgetClick",
            justDelivered: false,
        });
        expect(openSpy).not.toHaveBeenCalled();
        expect(source.readFileContent).not.toHaveBeenCalled();
        openSpy.mockRestore();
    });
    it("keeps the tab without a canvas surface, without a message and for another store", async () => {
        registerDriveFileCardsSource(buildSource(CARDS));
        registerExampleOpener();
        // a host without a split (the default context): the opener is not asked
        render(<DriveFilesConversationWidget payload={PAYLOAD} messageId={41}/>);
        await waitFor(() => expect(screen.getAllByTestId("drive-files-widget-card")).toHaveLength(3));
        expect(screen.queryAllByTestId("drive-files-widget-open-canvas")).toHaveLength(0);
        expect(screen.getAllByTestId("drive-files-widget-open")).toHaveLength(2);
        expect(driveFileCanvasTargetOf("drive", CARDS[1], 41)).toEqual({
            canvasId: "exampleDriveFile",
            instanceId: "exampleDriveFile:reference:drive/813",
            anchor: { kind: "message", messageId: 41 },
        });
        expect(driveFileCanvasTargetOf("drive", CARDS[1], undefined)).toBeNull();
        expect(driveFileCanvasTargetOf("drive", CARDS[2], 41)).toBeNull();
        expect(driveFileCanvasTargetOf("workspace", CARDS[1], 41)).toBeNull();
        // an opener that names a canvas nobody declared opens nothing
        unregisterCanvasKind("exampleDriveFile");
        expect(driveFileCanvasTargetOf("drive", CARDS[1], 41)).toBeNull();
    });
});
