// @vitest-environment jsdom
import type React from "react";
import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";
import { describe, expect, test } from "vitest";
import { act, fireEvent, render, renderHook, screen } from "@testing-library/react";
import { Provider } from "react-redux";
import {
  ConversationWidgetProvider,
  useConversationWidgetContext,
} from "../../renderer/ConversationWidgetContext";
import {
  buildMessageTextWithComposerWidgets,
  splitComposerWidgetFencesFromMessageText,
} from "../../renderer/composer-widget-fence";
import { WidgetRenderReportContext } from "../../renderer/render-report/widget-render-report";
import type { WidgetRenderReportEntry } from "../../renderer/render-report/widget-render-report";
import { EntityMentionConversationWidget } from "./EntityMentionConversationWidget";
import { EntityMentionComposerWidget } from "./EntityMentionComposerWidget";
import { EntityMentionAskButton } from "./EntityMentionAskButton/EntityMentionAskButton";
import { useEntityMentionComposer } from "./shared/use-entity-mention-composer";
import { MAX_ENTITY_MENTIONS_PER_MESSAGE } from "./shared/entity-mention";

// ConversationWidgetProvider dispatches on mount/unmount — a bare dispatch stub is enough (no Redux state is asserted).
const reduxStoreStub = {
  getState: () => ({}),
  subscribe: () => () => undefined,
  dispatch: (action: unknown) => action,
};

const wrapper = ({ children }: { children: React.ReactNode }) => (
  <Provider store={reduxStoreStub as never}>
    <ConversationWidgetProvider callbacks={{}}>{children}</ConversationWidgetProvider>
  </Provider>
);

const LEVE = { entityType: "competitor", entityId: "8812", label: "Altes Gasthaus Leve", agentSpaceKey: 7 };

/** The same entity as the fence carries it: the key of its agent space under the block field the app declares. */
const buildLeveWireFields = () => ({
  entityType: LEVE.entityType,
  entityId: LEVE.entityId,
  label: LEVE.label,
  [getWidgetSelfHydrationKeyFieldName()]: LEVE.agentSpaceKey,
});

const useHarness = () => ({ ...useEntityMentionComposer(), ...useConversationWidgetContext() });

describe("EntityMention — the entity the owner asks about", () => {
  test("the message echo shows the chip and reports mounted; a payload without an entity renders nothing and fails", () => {
    const reports: WidgetRenderReportEntry[] = [];
    const reporter = { report: (entry: WidgetRenderReportEntry) => reports.push(entry) };
    const { unmount } = render(
      <WidgetRenderReportContext.Provider value={reporter}>
        <EntityMentionConversationWidget payload={JSON.stringify(buildLeveWireFields())} />
      </WidgetRenderReportContext.Provider>,
    );
    expect(screen.getByTestId("entity-mention-message").textContent).toContain("Altes Gasthaus Leve");
    expect(screen.queryByRole("button")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "mounted" });
    unmount();

    const { container } = render(
      <WidgetRenderReportContext.Provider value={reporter}>
        <EntityMentionConversationWidget payload='{"label":"no entity"}' />
      </WidgetRenderReportContext.Provider>,
    );
    expect(container.textContent).toBe("");
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("a label with markup is shown as text", () => {
    render(<EntityMentionConversationWidget payload={JSON.stringify({ ...buildLeveWireFields(), label: "<b>Leve</b> & Söhne" })} />);
    expect(screen.getByTestId("entity-mention-message").textContent).toContain("<b>Leve</b> & Söhne");
  });

  test("the echo card shows the caption, and without a label names the type", () => {
    render(<EntityMentionConversationWidget payload={JSON.stringify({ entityType: "review", entityId: "4711" })} />);
    expect(screen.getByTestId("entity-mention-chip-caption").textContent).toBe("Asking about");
    expect(screen.getByTestId("entity-mention-chip-label").textContent).toBe("This review");
    expect(screen.getByTestId("entity-mention-chip-icon")).toBeTruthy();
  });

  test("an unknown type without a label falls back to About this entry", () => {
    render(<EntityMentionConversationWidget payload={JSON.stringify({ entityType: "searchTerm", entityId: "9" })} />);
    expect(screen.getByTestId("entity-mention-chip-label").textContent).toBe("About this entry");
  });

  test("the composer chip's ✕ removes the mention", () => {
    let removed = 0;
    render(
      <EntityMentionComposerWidget
        composerWidgetId="m1"
        fields={buildLeveWireFields()}
        replaceFields={() => undefined}
        remove={() => (removed += 1)}
      />,
    );
    fireEvent.click(screen.getByTestId("entity-mention-chip-remove"));
    expect(removed).toBe(1);
  });

  test("Ask about this puts ONE chip per entity into the composer and sends nothing", () => {
    const { result } = renderHook(useHarness, { wrapper });
    act(() => {
      result.current.mentionEntity(LEVE);
    });
    act(() => {
      result.current.mentionEntity({ ...LEVE, label: "renamed" });
    });
    expect(result.current.composerWidgets).toHaveLength(1);
    expect(result.current.composerWidgets[0]).toMatchObject({ widgetName: "EntityMention", fields: buildLeveWireFields() });
    expect(result.current.isMentioned(LEVE)).toBe(true);
    expect(result.current.mentionEntity({ entityType: "competitor", entityId: "" })).toBe(false);
  });

  test("past the cap the newest entity takes the place of the oldest mention", () => {
    const { result } = renderHook(useHarness, { wrapper });
    for (let mentionIndex = 0; mentionIndex <= MAX_ENTITY_MENTIONS_PER_MESSAGE; mentionIndex += 1) {
      act(() => {
        result.current.mentionEntity({ entityType: "review", entityId: String(mentionIndex) });
      });
    }
    expect(result.current.composerWidgets).toHaveLength(MAX_ENTITY_MENTIONS_PER_MESSAGE);
    expect(result.current.composerWidgets.map((widget) => widget.fields.entityId)).toEqual(["5", "1", "2", "3", "4"]);
  });

  test("the button adds the chip, then says it was added", () => {
    const Harness = () => {
      const { composerWidgets } = useConversationWidgetContext();
      return (
        <>
          <EntityMentionAskButton {...LEVE} />
          <span data-testid="held">{composerWidgets.length}</span>
        </>
      );
    };
    render(<Harness />, { wrapper });
    fireEvent.click(screen.getByTestId("entity-mention-ask"));
    expect(screen.getByTestId("held").textContent).toBe("1");
    expect(screen.getByTestId("entity-mention-ask").textContent).toContain("Added to your message");
  });

  test("the fence travels in the owner's message and comes back out as the same widget", () => {
    const messageText = buildMessageTextWithComposerWidgets("Why is their rating better than mine?", [
      { composerWidgetId: "m1", widgetName: "EntityMention", fields: buildLeveWireFields() },
    ]);
    expect(messageText).toContain('```widget\n{"type":"EntityMention","entityType":"competitor"');
    const split = splitComposerWidgetFencesFromMessageText(messageText);
    expect(split.text).toBe("Why is their rating better than mine?");
    expect(split.widgets).toEqual([{ composerWidgetId: "EntityMention:0", widgetName: "EntityMention", fields: buildLeveWireFields() }]);
  });
});
