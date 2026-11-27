import { describe, expect, it } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkGfm from "remark-gfm";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import conversationWidgetAstPlugin from "./conversationWidgetAstPlugin";
import restoreTextDirectivesPlugin from "./restoreTextDirectivesPlugin";
import { normalizeServerMessageToMarkdown } from "../chat/normalize-server-message-to-markdown";
import widgetBlockParityFixture from "./widget-block-parity.json";
const isBackendWidgetBlock = (value) => {
    if (typeof value !== "object" || value === null)
        return false;
    const candidate = value;
    return (typeof candidate.syntax === "string" &&
        typeof candidate.outcome === "string" &&
        typeof candidate.isClosed === "boolean" &&
        (typeof candidate.writtenWidgetType === "string" || candidate.writtenWidgetType === null) &&
        (typeof candidate.emittedBy === "string" || candidate.emittedBy === null));
};
/** Runtime guard so the fixture's shape is verified rather than blindly cast onto `WidgetBlockParityCase`. */
const isWidgetBlockParityCase = (value) => {
    if (typeof value !== "object" || value === null)
        return false;
    const candidate = value;
    return (typeof candidate.name === "string" &&
        typeof candidate.markdown === "string" &&
        Array.isArray(candidate.backend) &&
        candidate.backend.every(isBackendWidgetBlock) &&
        (candidate.frontendRenderedWidgetNames === null ||
            (Array.isArray(candidate.frontendRenderedWidgetNames) &&
                candidate.frontendRenderedWidgetNames.every((name) => typeof name === "string"))) &&
        (candidate.divergence === null || typeof candidate.divergence === "string"));
};
const cases = widgetBlockParityFixture.cases.map((candidate) => {
    if (!isWidgetBlockParityCase(candidate)) {
        throw new Error(`widget-block-parity.json: malformed fixture case ${JSON.stringify(candidate)}`);
    }
    return candidate;
});
/** The rendered widget nodes of a static chat bubble's exact remark pipeline. */
const renderedWidgetNodes = (rawMessage) => {
    const markdown = normalizeServerMessageToMarkdown(rawMessage);
    const processor = unified()
        .use(remarkParse)
        .use(remarkGfm)
        .use(remarkDirective)
        .use(remarkWidgetFencePlugin)
        .use(conversationWidgetAstPlugin)
        .use(restoreTextDirectivesPlugin);
    const tree = processor.runSync(processor.parse(markdown));
    const nodes = [];
    visit(tree, "leafDirective", (node) => {
        // `conversationWidgetAstPlugin` rewrites every rendered widget node's `data.hName` to the canonical registry
        // name — the same key `ConversationMarkdown`'s component map and `ConversationWidgetHost` mount by. A dropped
        // (unknown) widget has no `data.hName` (it was replaced with a plain text node), so it is never collected.
        if (hNameOf(node)) {
            nodes.push(node);
        }
    });
    return nodes;
};
const hNameOf = (node) => node.data?.hName;
/** The exact remark pipeline + rendered-widget-name extraction a static chat bubble runs. */
const renderedWidgetNames = (rawMessage) => renderedWidgetNodes(rawMessage).map((node) => hNameOf(node) ?? "");
describe("widget-block-parity fixture — frontend pipeline", () => {
    it.each(cases.map((testCase) => [testCase.name, testCase]))("%s", (_name, testCase) => {
        expect(testCase.frontendRenderedWidgetNames).not.toBeNull();
        expect(renderedWidgetNames(testCase.markdown)).toEqual(testCase.frontendRenderedWidgetNames);
    });
});
/**
 * A block the backend writer stores carries `emittedBy` as its last field. The frontend tolerates
 * it with no code change — the fence plugin moves every non-`type` field into the `payload` string, so the field rides
 * along inside the payload (every widget's parser picks the fields it knows) and never becomes a prop of its own: the
 * node's attributes, which `conversationWidgetAstPlugin` hands to the component as props, stay `payload` (+ the agent space key field the app declares).
 */
describe("widget-block-parity fixture — the emittedBy origin field", () => {
    const emittedByCases = cases.filter((testCase) => testCase.backend.some((block) => block.emittedBy !== null));
    it("lists at least one block with an origin", () => {
        expect(emittedByCases.length).toBeGreaterThan(0);
    });
    it.each(emittedByCases.map((testCase) => [testCase.name, testCase]))("%s: the field stays inside the payload, never a prop", (_name, testCase) => {
        const [node] = renderedWidgetNodes(testCase.markdown);
        expect(Object.keys(node.attributes ?? {})).toEqual(["payload"]);
        const payload = JSON.parse(String(node.attributes?.payload));
        expect(payload.emittedBy).toBe(testCase.backend[0].emittedBy);
        const payloadKeys = Object.keys(payload);
        expect(payloadKeys[payloadKeys.length - 1]).toBe("emittedBy");
        const withoutOrigin = testCase.markdown.replace(/,"emittedBy":"[^"]*"/, "");
        expect(withoutOrigin).not.toContain("emittedBy");
        expect(renderedWidgetNames(withoutOrigin)).toEqual(renderedWidgetNames(testCase.markdown));
    });
});
