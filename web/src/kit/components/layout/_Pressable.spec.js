// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { act, fireEvent, screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";
/**
 * `Pressable` through the renderer: a click writes its value into state and every bound node
 * follows, `isSelected` draws the ring and sets aria-pressed, an action click runs the action, the e-mail capture has
 * no control.
 */
const channelTiles = {
    state: { channel: "Organic" },
    computed: { isOrganic: 'channel == "Organic"', isPaid: 'channel == "Paid"' },
    view: {
        type: "Stack",
        children: [
            {
                type: "Pressable",
                onClick: { set: "channel" },
                value: "Organic",
                isSelected: { $computed: "isOrganic" },
                label: "Show the organic channel",
                children: [{ type: "Surface", title: "Organic" }],
            },
            {
                type: "Pressable",
                onClick: { set: "channel" },
                value: "Paid",
                isSelected: { $computed: "isPaid" },
                label: "Show the paid channel",
                children: [{ type: "Surface", title: "Paid" }],
            },
            { type: "Quote", text: { $state: "channel" } },
        ],
    },
};
describe("Pressable", () => {
    test("a click writes its value into state; the selection follows", () => {
        const { container, reports } = renderLayoutTree(channelTiles);
        const organic = screen.getByRole("button", { name: "Show the organic channel" });
        const paid = screen.getByRole("button", { name: "Show the paid channel" });
        expect(organic.getAttribute("aria-pressed")).toBe("true");
        expect(paid.getAttribute("aria-pressed")).toBe("false");
        act(() => {
            fireEvent.click(paid);
        });
        expect(paid.getAttribute("aria-pressed")).toBe("true");
        expect(organic.getAttribute("aria-pressed")).toBe("false");
        expect(container.querySelector("blockquote")?.textContent).toBe("Paid");
        expect(paid.parentElement?.className).toMatch(/selected/);
        expect(reports.at(-1)).toEqual({ status: "mounted" });
    });
    test("an action click runs the tree's action", () => {
        const { container } = renderLayoutTree({
            ...channelTiles,
            actions: { reset: { type: "resetState" } },
            view: {
                type: "Stack",
                children: [
                    ...channelTiles.view.children,
                    {
                        type: "Pressable",
                        onClick: { action: "reset" },
                        label: "Reset",
                        children: [{ type: "Quote", text: "Reset" }],
                    },
                ],
            },
        });
        act(() => {
            fireEvent.click(screen.getByRole("button", { name: "Show the paid channel" }));
        });
        act(() => {
            fireEvent.click(screen.getByRole("button", { name: "Reset" }));
        });
        expect(container.querySelector("blockquote")?.textContent).toBe("Organic");
    });
    test("the e-mail capture shows the content without a control and keeps the selection", () => {
        const { container } = renderLayoutTree(channelTiles, true);
        expect(screen.queryByRole("button")).toBeNull();
        expect(screen.getAllByText("Organic").length).toBeGreaterThan(0);
        expect(container.querySelectorAll("[class*='selected']")).toHaveLength(1);
    });
    test("a picture inside a tile opens nothing: the whole tile is the one button, its text is padded off the ring", () => {
        const { container } = renderLayoutTree({
            state: { picked: "" },
            view: {
                type: "Pressable",
                onClick: { set: "picked" },
                value: "16844",
                label: "Pick picture 16844",
                children: [
                    { type: "Image", src: "https://example.org/16844.png", alt: "Picture 16844" },
                    { type: "Quote", text: "#16844" },
                ],
            },
        });
        expect(screen.getAllByRole("button")).toHaveLength(1);
        expect(container.querySelector("[data-testid$='-tile-open']")).toBeNull();
        expect(screen.getByRole("button", { name: "Pick picture 16844" }).className).toMatch(/padded/);
    });
    test("a tile whose content is one Surface is not padded again", () => {
        renderLayoutTree(channelTiles);
        expect(screen.getByRole("button", { name: "Show the organic channel" }).className).not.toMatch(/padded/);
    });
});
