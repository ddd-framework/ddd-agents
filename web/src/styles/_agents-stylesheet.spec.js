import * as path from "node:path";
import { compile } from "sass";
import { describe, expect, test } from "vitest";
/**
 * The package stylesheet as a host meets it: its root rules sit in the `agents-defaults` layer (a host's layered reset
 * or plain rule wins), the dark set also keys on `data-agents-theme`, every plain colour of the 0.1 triplets exists and
 * defaults to its triplet, the accent foreground follows the theme, and Markdown sets its structure explicitly.
 */
const stylesheet = compile(path.resolve(__dirname, "agents.scss")).css;
const outsideTheLayer = stylesheet.replace(/@layer agents-defaults \{[\s\S]*?\n\}/g, "");
describe("the package stylesheet", () => {
    test("keeps every :root, html and body rule in the agents-defaults layer", () => {
        expect(stylesheet).toContain("@layer agents-defaults");
        expect(outsideTheLayer).not.toMatch(/(^|[\s,}]):root\b/);
        expect(outsideTheLayer).not.toMatch(/(^|[\s,}])(html|body)\s*[,{]/);
    });
    test("switches to the dark set on data-agents-theme, which pins light over prefers-color-scheme and data-theme", () => {
        expect(stylesheet).toContain(':root:not([data-theme=light]):not([data-agents-theme=light])');
        expect(stylesheet).toContain(':root[data-theme=dark]:not([data-agents-theme=light])');
        expect(stylesheet).toContain(":root[data-agents-theme=dark]");
    });
    test("derives each plain colour of the triplet list from its triplet", () => {
        for (const name of ["bg", "fg", "fg-cta", "bg-dark", "shadow", "neutral-500", "neutral-900", "danger-500"]) {
            expect(stylesheet).toContain(`--agents-${name}-rgb:`);
            expect(stylesheet).toContain(`--agents-${name}: rgb(var(--agents-${name}-rgb));`);
        }
    });
    test("has a theme-following foreground for accent fills", () => {
        expect(stylesheet).toMatch(/--agents-fg-on-accent: #ffffff;[\s\S]*--agents-fg-on-accent: #111418;/);
    });
    test("sets list markers, heading weight and link underline of Markdown explicitly", () => {
        expect(stylesheet).toMatch(/\.agents-markdown ul \{[^}]*list-style-type: disc;/);
        expect(stylesheet).toMatch(/\.agents-markdown ol \{[^}]*list-style-type: decimal;/);
        expect(stylesheet).toMatch(/\.agents-markdown h1,[\s\S]*?font-weight: 700;/);
        expect(stylesheet).toMatch(/\.agents-markdown a \{[^}]*text-decoration-line: underline;/);
    });
});
