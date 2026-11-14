import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import { describe, expect, it } from "vitest";
import { conversationWidgetRegistry, packageConversationWidgets } from "./conversationWidgetRegistry";
import { isCanvasId, packageCanvasBehaviours } from "./canvas/canvas-behaviours";
import { markdownContainsWidget } from "./markdown-contains-widget";
import { resolveWidgetName } from "./widget-name-resolver";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import conversationWidgetAstPlugin from "./conversationWidgetAstPlugin";

/**
 * G-REG — the frozen RESOLUTION CONTRACT of the package's own widgets.
 *
 * THIS SPEC IS THE BACKWARDS-COMPATIBILITY GUARANTEE FOR STORED CONVERSATIONS. Conversation messages live in the
 * database forever and are never rewritten: an old row carries `::MultiChoiceDirective{payload='…'}` or a ```widget
 * fence with `"type": "MultiChoiceDirective"`, a new row carries the canonical `MultiChoice`. Both must render the
 * same component, in every one of the pipeline's lookup sites.
 *
 * The two lists below are frozen for different reasons:
 *   - PACKAGE_CANONICAL_WIRE_NAMES — what the package registers TODAY. A NEW package widget APPENDS its canonical name
 *     here. Removing one is a decommission: the widget stops rendering everywhere.
 *   - PACKAGE_LEGACY_WIRE_NAMES    — the historical long forms sitting in the database. **These rows may NEVER be
 *     removed.** Each one must keep resolving to its canonical counterpart through the `Directive`-suffix strip rule.
 *
 * An app freezes its own widgets' names in a spec of its own. A canonical name may never end in `Directive` — that
 * suffix is reserved as the legacy marker and asserted below for every registered widget.
 */
const PACKAGE_CANONICAL_WIRE_NAMES = [
  "Clock",
  "ComposedView",
  "ConceptExplainer",
  "ConversationSearchResults",
  "CurrencyConverter",
  "CustomVisualization",
  "EntityMention",
  "MultiChoice",
  "QuickReplies",
  "SuggestedActions",
  "ToolTrace",
  "WebSearchResults",
  "WritingDraft",
];

/**
 * The names stored conversations were written with before the names were canonicalized — the registry `name:`
 * strings of that era, minus the one that was already short (`SuggestedActions`, its own legacy form, covered by the
 * exact-match half of the rule).
 */
const PACKAGE_LEGACY_WIRE_NAMES = [
  "ConversationSearchResultsDirective",
  "MultiChoiceDirective",
  "ToolTraceDirective",
  "WebSearchResultsDirective",
];

const registeredEntries = () => conversationWidgetRegistry.widgets;

const registeredWireNames = (): string[] =>
  registeredEntries()
    .map((entry) => entry.name)
    .sort();

const componentForCanonicalName = (name: string) => registeredEntries().find((entry) => entry.name === name)?.component;

/** The `hName` the AST pipeline assigns to a `::Name{…}` node — i.e. which component the message mounts. */
const renderedComponentNameForWidget = (markdown: string): string | undefined => {
  const processor = unified()
    .use(remarkParse)
    .use(remarkDirective)
    .use(remarkWidgetFencePlugin)
    .use(conversationWidgetAstPlugin);
  const tree = processor.runSync(processor.parse(markdown));
  let hName: string | undefined;
  visit(tree, (node: any) => {
    if (hName === undefined && node.data?.hName) hName = node.data.hName as string;
  });
  return hName;
};

describe("conversationWidgetRegistry — the frozen canonical wire names", () => {
  it("the package's own widgets are exactly the frozen canonical name list", () => {
    expect(packageConversationWidgets.map((entry) => entry.name).sort()).toEqual([...PACKAGE_CANONICAL_WIRE_NAMES].sort());
  });

  it("the registry holds every package widget (an app's registrations come on top)", () => {
    expect(registeredWireNames()).toEqual(expect.arrayContaining(PACKAGE_CANONICAL_WIRE_NAMES));
  });

  it("registers every name exactly once — a duplicate would shadow one component", () => {
    const names = registeredWireNames();
    expect(names).toEqual([...new Set(names)]);
  });

  it("registers NO canonical name ending in `Directive` — the suffix is reserved as the legacy marker", () => {
    expect(registeredWireNames().filter((name) => name.endsWith("Directive"))).toEqual([]);
  });

  it("lists only declared canvas ids in opensCanvases", () => {
    const listed = registeredEntries().flatMap((entry) => entry.opensCanvases ?? []);
    expect(listed.filter((canvasId) => !isCanvasId(canvasId))).toEqual([]);
    // The package's own widgets open the package's own canvases only: an app's canvases are not there without the app.
    expect(Object.keys(packageCanvasBehaviours)).toEqual(
      expect.arrayContaining(packageConversationWidgets.flatMap((entry) => entry.opensCanvases ?? [])),
    );
  });

  it("requires every entry with a composerComponent to also declare a component (message rendering)", () => {
    const offenders = registeredEntries()
      .filter((entry) => entry.composerComponent !== undefined && !entry.component)
      .map((entry) => entry.name);
    expect(offenders).toEqual([]);
  });
});

describe("resolveWidgetName — the legacy `Directive`-suffix rule", () => {
  it("resolves every legacy long name to the same component as its canonical counterpart", () => {
    for (const legacyName of PACKAGE_LEGACY_WIRE_NAMES) {
      const canonicalName = legacyName.replace(/Directive$/, "");
      expect(resolveWidgetName(legacyName), `legacy name ${legacyName} no longer resolves`).toBe(canonicalName);
      expect(componentForCanonicalName(canonicalName), `no component registered for ${canonicalName}`).toBeDefined();
      expect(componentForCanonicalName(resolveWidgetName(legacyName) as string)).toBe(
        componentForCanonicalName(resolveWidgetName(canonicalName) as string),
      );
    }
  });

  it("resolves the legacy and the canonical form of one widget identically", () => {
    expect(resolveWidgetName("MultiChoiceDirective")).toBe(resolveWidgetName("MultiChoice"));
    expect(resolveWidgetName("MultiChoice")).toBe("MultiChoice");
  });

  it("resolves a canonical name that never had a suffix", () => {
    expect(resolveWidgetName("SuggestedActions")).toBe("SuggestedActions");
  });

  it("strips the suffix only ONCE and only when the remainder is registered", () => {
    expect(resolveWidgetName("MultiChoiceDirectiveDirective")).toBeUndefined();
    expect(resolveWidgetName("NotAWidgetDirective")).toBeUndefined();
  });

  it("returns undefined for an unknown name", () => {
    expect(resolveWidgetName("NotAWidget")).toBeUndefined();
  });
});

describe("the pipeline resolves both forms — fence, bare paragraph and `::` node", () => {
  it("renders a ```widget fence in both the canonical and the legacy form", () => {
    expect(renderedComponentNameForWidget('```widget\n{ "type": "MultiChoice" }\n```')).toBe("MultiChoice");
    expect(renderedComponentNameForWidget('```widget\n{ "type": "MultiChoiceDirective" }\n```')).toBe("MultiChoice");
  });

  it("renders a bare `widget { … }` paragraph in both forms", () => {
    expect(renderedComponentNameForWidget('widget { "type": "WebSearchResults" }')).toBe("WebSearchResults");
    expect(renderedComponentNameForWidget('widget { "type": "WebSearchResultsDirective" }')).toBe(
      "WebSearchResults",
    );
  });

  it("renders a stored `::Name{…}` node in both forms", () => {
    expect(renderedComponentNameForWidget("::ToolTrace")).toBe("ToolTrace");
    expect(renderedComponentNameForWidget("::ToolTraceDirective")).toBe("ToolTrace");
  });

  it("renders nothing for an unknown widget name in either form", () => {
    expect(renderedComponentNameForWidget('```widget\n{ "type": "NotAWidget" }\n```')).toBeUndefined();
    expect(renderedComponentNameForWidget("::NotAWidgetDirective")).toBeUndefined();
  });
});

describe("markdownContainsWidget — drawer reset recognizes both forms", () => {
  it("recognizes a closed fence whose type is a registered widget, canonical or legacy", () => {
    expect(markdownContainsWidget('Here it is:\n\n```widget\n{ "type": "ConversationSearchResults" }\n```')).toBe(true);
    expect(markdownContainsWidget('Here it is:\n\n```widget\n{ "type": "ConversationSearchResultsDirective" }\n```')).toBe(
      true,
    );
  });

  it("recognizes a stored `::Name` node, canonical or legacy", () => {
    expect(markdownContainsWidget("Here it is:\n\n::ConversationSearchResults")).toBe(true);
    expect(markdownContainsWidget("Here it is:\n\n::ConversationSearchResultsDirective{payload='{}'}")).toBe(true);
  });

  it("rejects a closed fence whose type is not registered", () => {
    expect(markdownContainsWidget('Here it is:\n\n```widget\n{ "type": "NotAWidget" }\n```')).toBe(false);
  });
});
