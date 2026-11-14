import { describe, expect, test } from "vitest";
import {
  assertUniqueConversationWidgetNames,
  conversationWidgetRegistry,
  readFolderRegisteredConversationWidgets,
} from "./conversationWidgetRegistry";

/**
 * A widget registers itself with a `*.widget-registration.ts` file next to its component; the
 * registry takes every such file, and a name registered twice is refused.
 */
describe("conversation widget registration by file", () => {
  test("a registration file joins the registry", () => {
    const folderRegisteredNames = readFolderRegisteredConversationWidgets().map((entry) => entry.name);
    expect(folderRegisteredNames).toContain("ComposedView");
    expect(conversationWidgetRegistry.widgets.map((entry) => entry.name)).toEqual(
      expect.arrayContaining(folderRegisteredNames),
    );
  });

  test("a name registered twice is refused", () => {
    const [firstEntry] = conversationWidgetRegistry.widgets;
    expect(() => assertUniqueConversationWidgetNames([firstEntry, { ...firstEntry }])).toThrow(/registered twice/);
    expect(() => assertUniqueConversationWidgetNames(conversationWidgetRegistry.widgets)).not.toThrow();
  });
});
