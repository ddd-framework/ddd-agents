import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { AgentsProvider } from "./AgentsProvider";
import { getAgentsEnvironment, openImageGallery, setAgentsEnvironment } from "./environment";
import { AgentsLink, useAgentsLinkNavigation } from "./link-navigation";
/**
 * The link navigation: every package link is a new-tab anchor unless the host's `onNavigate` takes the click, and a
 * link the package opens by code goes through the same decision before `window.open`.
 */
const OpenLinkButton = (props) => {
    const { openLink } = useAgentsLinkNavigation();
    return (<button type="button" onClick={(event) => openLink(props.href, event)}>
      open
    </button>);
};
describe("link navigation", () => {
    const appEnvironment = getAgentsEnvironment();
    afterEach(() => {
        cleanup();
        setAgentsEnvironment(appEnvironment);
        vi.restoreAllMocks();
    });
    test("an anchor opens in a new tab without a host decision", () => {
        render(<AgentsLink href="https://example.test/page">Page</AgentsLink>);
        const anchor = screen.getByRole("link", { name: "Page" });
        expect(anchor).toHaveAttribute("target", "_blank");
        expect(anchor).toHaveAttribute("rel", "noopener noreferrer");
        expect(fireEvent.click(anchor)).toBe(true);
    });
    test("the host's onNavigate takes a click it handles and leaves the rest to the browser", () => {
        const onNavigate = vi.fn((href) => href.startsWith("/app/"));
        render(<AgentsProvider environment={{ onNavigate }}>
        <AgentsLink href="/app/reviews/4">In app</AgentsLink>
        <AgentsLink href="https://example.test/">Outside</AgentsLink>
      </AgentsProvider>);
        const isInAppClickFollowed = fireEvent.click(screen.getByRole("link", { name: "In app" }));
        const isOutsideClickFollowed = fireEvent.click(screen.getByRole("link", { name: "Outside" }));
        expect(isInAppClickFollowed).toBe(false);
        expect(isOutsideClickFollowed).toBe(true);
        expect(onNavigate).toHaveBeenCalledWith("/app/reviews/4", expect.any(MouseEvent));
    });
    test("a link opened by code asks the host first, then opens a new tab", () => {
        const openWindow = vi.spyOn(window, "open").mockReturnValue(null);
        const onNavigate = vi.fn((href) => href === "/app/video");
        render(<AgentsProvider environment={{ onNavigate }}>
        <OpenLinkButton href="/app/video"/>
      </AgentsProvider>);
        fireEvent.click(screen.getByRole("button", { name: "open" }));
        expect(openWindow).not.toHaveBeenCalled();
        cleanup();
        render(<OpenLinkButton href="https://video.test/watch"/>);
        fireEvent.click(screen.getByRole("button", { name: "open" }));
        expect(openWindow).toHaveBeenCalledWith("https://video.test/watch", "_blank", "noopener,noreferrer");
    });
    test("a gallery image without a lightbox goes through the app's onNavigate", () => {
        const openWindow = vi.spyOn(window, "open").mockReturnValue(null);
        const onNavigate = vi.fn(() => true);
        setAgentsEnvironment({ onNavigate });
        openImageGallery([{ id: "a", url: "https://images.test/a.png" }], 0);
        expect(onNavigate).toHaveBeenCalledWith("https://images.test/a.png", null);
        expect(openWindow).not.toHaveBeenCalled();
    });
});
