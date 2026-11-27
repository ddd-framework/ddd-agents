import { describe, expect, it } from "vitest";
import { isAllowedMarkdownImageSource } from "./markdown-image-source";
const PAGE_ORIGIN = "https://app.example.com";
describe("isAllowedMarkdownImageSource", () => {
    it("allows the page's own origin, relative URLs, blob and inline raster data", () => {
        expect(isAllowedMarkdownImageSource("https://app.example.com/img/a.png", PAGE_ORIGIN, [])).toBe(true);
        expect(isAllowedMarkdownImageSource("/api/media/1", PAGE_ORIGIN, [])).toBe(true);
        expect(isAllowedMarkdownImageSource("blob:https://app.example.com/1", PAGE_ORIGIN, [])).toBe(true);
        expect(isAllowedMarkdownImageSource("data:image/png;base64,AAAA", PAGE_ORIGIN, [])).toBe(true);
    });
    it("refuses foreign hosts unless listed, and never svg data or other schemes", () => {
        expect(isAllowedMarkdownImageSource("https://evil.example.net/?d=secret", PAGE_ORIGIN, [])).toBe(false);
        expect(isAllowedMarkdownImageSource("https://cdn.example.org/a.png", PAGE_ORIGIN, ["cdn.example.org"])).toBe(true);
        expect(isAllowedMarkdownImageSource("https://a.media.example.org/a.png", PAGE_ORIGIN, ["*.media.example.org"])).toBe(true);
        expect(isAllowedMarkdownImageSource("https://media.example.org.evil.net/a.png", PAGE_ORIGIN, ["*.media.example.org"])).toBe(false);
        expect(isAllowedMarkdownImageSource("http://cdn.example.org/a.png", PAGE_ORIGIN, ["cdn.example.org"])).toBe(false);
        expect(isAllowedMarkdownImageSource("data:image/svg+xml;base64,AAAA", PAGE_ORIGIN, [])).toBe(false);
        expect(isAllowedMarkdownImageSource("javascript:alert(1)", PAGE_ORIGIN, [])).toBe(false);
        expect(isAllowedMarkdownImageSource("", PAGE_ORIGIN, [])).toBe(false);
    });
});
