import { afterEach, describe, expect, test } from "vitest";
import { AGENTS_THEME_ATTRIBUTE_NAME, followHostColorScheme } from "./host-color-scheme";
/** `followHostColorScheme`: the package's scheme attribute mirrors a host theme kept as a class or an attribute. */
const waitForMutationObserver = () => new Promise((resolveWait) => setTimeout(resolveWait, 0));
describe("followHostColorScheme", () => {
    afterEach(() => {
        document.documentElement.className = "";
        document.documentElement.removeAttribute("data-mode");
    });
    test("follows a class on the root element and removes the attribute when stopped", async () => {
        const stop = followHostColorScheme({ darkClassName: "dark" });
        expect(document.documentElement.getAttribute(AGENTS_THEME_ATTRIBUTE_NAME)).toBe("light");
        document.documentElement.classList.add("dark");
        await waitForMutationObserver();
        expect(document.documentElement.getAttribute(AGENTS_THEME_ATTRIBUTE_NAME)).toBe("dark");
        stop();
        expect(document.documentElement.hasAttribute(AGENTS_THEME_ATTRIBUTE_NAME)).toBe(false);
    });
    test("follows an attribute with its dark value", async () => {
        document.documentElement.setAttribute("data-mode", "night");
        const stop = followHostColorScheme({ attributeName: "data-mode", darkAttributeValue: "night" });
        expect(document.documentElement.getAttribute(AGENTS_THEME_ATTRIBUTE_NAME)).toBe("dark");
        document.documentElement.setAttribute("data-mode", "day");
        await waitForMutationObserver();
        expect(document.documentElement.getAttribute(AGENTS_THEME_ATTRIBUTE_NAME)).toBe("light");
        stop();
    });
});
