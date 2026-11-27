import { execFileSync } from "node:child_process";
import * as fs from "node:fs";
import * as path from "node:path";
import { describe, expect, test } from "vitest";
/**
 * `i18n-keys.json`, the manifest of the package's text keys: it is what the sources give (`pnpm i18n-keys` rewrites it),
 * and it lists the chat's own texts — the activity line, the composer, the empty and loading states, the error card.
 * The generator runs as its own process: esbuild, which it transforms the sources with, does not run under jsdom.
 */
const packageDir = path.resolve(__dirname, "..");
const generatorScript = path.join(packageDir, "scripts", "i18n-keys.mjs");
const committedManifestText = () => fs.readFileSync(path.join(packageDir, "src", "i18n-keys.json"), "utf-8");
const generateManifestText = () => execFileSync(process.execPath, [generatorScript], { cwd: packageDir, encoding: "utf-8" });
describe("i18n-keys.json", () => {
    test("is fresh: the committed manifest is what the sources give", () => {
        expect(committedManifestText()).toBe(generateManifestText());
    });
    test("lists the chat's texts with their English default, placeholders, contexts and files", () => {
        const { keys } = JSON.parse(committedManifestText());
        const entryOf = (key) => keys.find((entry) => entry.key === key);
        expect(entryOf("Working on it…")?.usedIn).toContain("src/chat/AgentChatActivityIndicator/AgentChatActivityIndicator.tsx");
        expect(entryOf("Ask anything")?.usedIn).toContain("src/chat/ConversationMessageInput/ConversationMessageInput.tsx");
        expect(entryOf("Loading older messages…")).toBeDefined();
        expect(entryOf("Network error")?.usedIn).toContain("src/chat/ConversationErrorWidget/ConversationErrorWidget.tsx");
        expect(entryOf("You declined a voice chat with %agentName%")).toMatchObject({
            defaultText: "You declined a voice chat with %agentName%",
            placeholders: ["agentName"],
        });
        expect(entryOf("%count% place")?.contexts).toEqual(["one", "other"]);
        // A text the package shows as it is (a model's words, a number) is no key.
        expect(keys.every((entry) => /\p{L}/u.test(entry.key))).toBe(true);
    });
});
