import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { describe, expect, it, vi } from "vitest";
import { installFakeBrowser } from "./call/agent-voice-call-store.spec-helper";
import { DEFAULT_AGENTS_TEXTS } from "../provider/environment";
import { textKey } from "../provider/text-key";
// The store modules import React at runtime; the stand-in of the spec helper replaces it.
vi.mock("react", async () => (await import("./call/agent-voice-call-store.spec-helper")).createFakeReactModule());
// `node scripts/i18n-keys.mjs --check` (part of the build) and `src/_i18n-keys.spec.ts` already prove that the manifest
// is what the sources give: the keys the translate functions, the translating components and the marker `textKey(…)`
// are handed. What they cannot see: a text that reaches the translate function through a store's state or a table
// without the `textKey` marker (it would render, but never be in the manifest), and the keys the logic hands out at
// runtime. Those two are checked here for the voice components. In `admin/` the only non-literal texts are the status
// map entries; their check is the describe "the texts of the admin screens" below.
const sourceRoot = __dirname;
const manifest = JSON.parse(readFileSync(join(__dirname, "..", "i18n-keys.json"), "utf8"));
const manifestKeys = new Set(manifest.keys.map(entry => entry.key));
const CHECKED_FOLDERS = [
    "call",
    "ring",
    "AgentVoiceCallBand",
    "AgentVoiceCallCaptions",
    "AgentVoiceCallProvider",
    "AgentVoiceChatStartButton",
    "AgentVoiceIcons",
    "AgentVoiceIncomingCall",
    "AgentVoiceInputGlow",
];
const listSourceFiles = (folder) => readdirSync(folder).flatMap(name => {
    const path = join(folder, name);
    if (statSync(path).isDirectory())
        return listSourceFiles(path);
    return /\.tsx?$/.test(name) && !/\.(?:spec|test)\.tsx?$/.test(name) && !/\.spec-helper\.ts$/.test(name) ? [path] : [];
});
const checkedSourceFiles = CHECKED_FOLDERS.flatMap(folder => listSourceFiles(join(sourceRoot, folder))).map(path => ({
    name: relative(sourceRoot, path),
    source: readFileSync(path, "utf8"),
}));
describe("textKey", () => {
    it("returns the key it marks", () => {
        expect(textKey("Voice chat ended")).toBe("Voice chat ended");
    });
});
// The untranslated default of the voice texts (the caller line of an unknown label key uses it).
describe("DEFAULT_AGENTS_TEXTS.translate", () => {
    it("fills every placeholder it has a value for, numbers as text", () => {
        expect(DEFAULT_AGENTS_TEXTS.translate("%agentName% talked %minutes% min", { agentName: "Sam", minutes: 3 })).toBe("Sam talked 3 min");
        expect(DEFAULT_AGENTS_TEXTS.translate("%agentName% and %agentName%", { agentName: "Sam" })).toBe("Sam and Sam");
    });
    it("leaves a placeholder without a value, a value of the object prototype and a marked span that is no placeholder", () => {
        expect(DEFAULT_AGENTS_TEXTS.translate("%agentName% wants to talk")).toBe("%agentName% wants to talk");
        expect(DEFAULT_AGENTS_TEXTS.translate("%agentName% at %toString%", { agentName: "Sam" })).toBe("Sam at %toString%");
        expect(DEFAULT_AGENTS_TEXTS.translate("100% sure, %not a name%", { agentName: "Sam" })).toBe("100% sure, %not a name%");
    });
});
describe("the text tables and constants of the voice components", () => {
    it("mark every value of a table of text keys with textKey", () => {
        const tablePattern = /const\s+(\w+_TEXT_KEYS)\s*:[^=]*=\s*\{([\s\S]*?)\n\};/g;
        const unmarkedValues = [];
        let tableCount = 0;
        for (const { name, source } of checkedSourceFiles) {
            for (const [, tableName, tableBody] of source.matchAll(tablePattern)) {
                tableCount += 1;
                for (const line of tableBody.split("\n")) {
                    const value = line.match(/^\s*(?:'[^']*'|"[^"]*"|\w+)\s*:\s*(.+?),?\s*$/)?.[1];
                    if (value !== undefined && !value.startsWith("textKey("))
                        unmarkedValues.push(`${name} ${tableName}: ${value}`);
                }
            }
        }
        expect(tableCount >= 3, `expected the text tables of the store, the answer and the band, found ${tableCount}`).toBe(true);
        expect(unmarkedValues).toStrictEqual([]);
    });
    it("mark every constant of a text key with textKey", () => {
        const unmarkedConstants = [];
        for (const { name, source } of checkedSourceFiles) {
            for (const [, constantName, value] of source.matchAll(/const\s+(\w+_TEXT_KEY)\s*=\s*([^;]+);/g)) {
                if (!value.trim().startsWith("textKey("))
                    unmarkedConstants.push(`${name} ${constantName}`);
            }
        }
        expect(unmarkedConstants).toStrictEqual([]);
    });
});
describe("the texts of the admin screens", () => {
    it("reach t(…) from a variable only as a label or description of a status map, with the map entry’s own context", () => {
        // The literal t(…) and m(…) texts are the manifest script's. A text from a variable is looked up as
        // `<text>__<context>`: the status maps' entries carry both, and `admin/_agent-voice-admin-format.spec.ts` checks
        // every entry against the manifest at runtime. Anything else from a variable would reach the text function unchecked.
        const statusMapLookup = /^t\((AGENT_VOICE_CALL_[A-Z_]+)\[(\w+)\]\.(?:label|description), \{ context: \1\[\2\]\.context \}\)/;
        const uncheckedLookups = [];
        for (const path of listSourceFiles(join(sourceRoot, "admin"))) {
            const source = readFileSync(path, "utf8");
            for (const match of source.matchAll(/\bt\(\s*(?!['"`])/g)) {
                const lookup = source.slice(match.index, match.index + 200);
                if (!statusMapLookup.test(lookup))
                    uncheckedLookups.push(`${relative(sourceRoot, path)}: ${lookup.split("\n")[0]}`);
            }
        }
        expect(uncheckedLookups).toStrictEqual([]);
    });
});
describe("the keys the logic hands out at runtime", () => {
    it("are keys of the manifest: the refusals of an answer and the caller line of a ring", async () => {
        installFakeBrowser();
        const { AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS } = await import("./ring/answer-incoming-agent-voice-call");
        const { pickAgentVoiceCallerLabelTextKey } = await import("./ring/agent-voice-caller-label");
        const handedOutKeys = [
            ...Object.values(AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS),
            pickAgentVoiceCallerLabelTextKey("").labelTextKey,
            pickAgentVoiceCallerLabelTextKey("%agentName% is calling you").labelTextKey,
        ];
        expect(handedOutKeys.filter(key => !manifestKeys.has(key))).toStrictEqual([]);
    });
});
