// @vitest-environment jsdom
import { act, fireEvent, render, renderHook, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import * as chatEntry from "./chat";
import * as clientTestingEntry from "./client-testing";
import * as contractEntry from "./contract";
import * as rootEntry from "./index";
import * as previewEntry from "./preview";
import * as rendererEntry from "./renderer";
import { PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES, SIGNAL_SOURCES_THAT_OWE_NO_WORK, } from "../chat/last-wire-message-is-unanswered-user-message";
import { getEntityDetailBody } from "../kit/entity-detail/entity-detail-bodies";
import { useGoogleMapsAuthFailure } from "../kit/components/map/use-google-maps-auth-failure";
import { buildChangedMessagesRequest } from "../chat/controller/changed-messages-request";
import { getWidgetSelfHydrationKeyFieldName } from "../provider/environment";
import { ConversationDeepLinkedWidget } from "../renderer/ConversationDeepLinkedWidget";
import { clearStoredCanvas, readStoredCanvas, storeOpenCanvas } from "../renderer/canvas/canvasStorage";
import { readOpenEntityActionTarget } from "../renderer/canvas/entity-detail-instance";
import { PACKAGE_WIDGET_CLICK_SIGNAL_SOURCES } from "../renderer/widget-click-signal-sources";
import { composedViewTreeToWire } from "../renderer/widget-tree/composed-view-tree";
import { EntityMentionAskFlowStory } from "../widgets/EntityMentionConversationWidget/stories/EntityMentionAskFlowStory";
/**
 * The names the public entries export beside the components: the signal-source lists and the page-opened widget mount
 * of `./chat`, the changed-messages request of `./contract`, the widget click
 * sources, the kept tree's wire form and the reading of an `openEntity` action of `./renderer`, the Maps auth-failure switch and the stored canvas pointer of `./client/testing`, the lookup
 * of one entity detail body of the root entry and the ask-flow story body of `./preview`. Each is the module's own
 * binding, so an app reads the same list, the same registry and flips the same flag the package's components use.
 */
describe("public entry exports", () => {
    afterEach(() => {
        clientTestingEntry.resetGoogleMapsAuthFailureForTests();
    });
    it("./chat exports the phone-call lifecycle sources and the sources that owe no work", () => {
        expect(chatEntry.PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES).toBe(PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES);
        expect(chatEntry.SIGNAL_SOURCES_THAT_OWE_NO_WORK).toBe(SIGNAL_SOURCES_THAT_OWE_NO_WORK);
        expect(chatEntry.SIGNAL_SOURCES_THAT_OWE_NO_WORK).toEqual(expect.arrayContaining([...PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES]));
    });
    it("./renderer exports the widget click sources and their predicate", () => {
        expect(rendererEntry.getWidgetClickSignalSources()).toEqual(expect.arrayContaining([...PACKAGE_WIDGET_CLICK_SIGNAL_SOURCES]));
        expect(rendererEntry.isWidgetClickSignalSource("FRONTEND_WIDGET_CLICK")).toBe(true);
        expect(rendererEntry.isWidgetClickSignalSource("PHONE_CALL_ENDED")).toBe(false);
        expect(rendererEntry.isWidgetClickSignalSource(undefined)).toBe(false);
    });
    it("./client/testing flips and resets the auth-failure flag the map component reads", () => {
        const { result, rerender } = renderHook(() => useGoogleMapsAuthFailure());
        expect(result.current).toBe(false);
        act(() => clientTestingEntry.simulateGoogleMapsAuthFailure());
        expect(result.current).toBe(true);
        clientTestingEntry.resetGoogleMapsAuthFailureForTests();
        rerender();
        expect(result.current).toBe(false);
    });
    it("./renderer exports the wire form of a kept tree", () => {
        expect(rendererEntry.composedViewTreeToWire).toBe(composedViewTreeToWire);
        const tree = rendererEntry.readComposedViewTree({ title: "Totals", view: { type: "Text", text: "Hello" } });
        expect(rendererEntry.composedViewTreeToWire(tree)).toEqual({ title: "Totals", view: { type: "Text", text: "Hello" } });
    });
    it("./renderer exports the reading of the entity an openEntity action opens", () => {
        expect(rendererEntry.readOpenEntityActionTarget).toBe(readOpenEntityActionTarget);
        const entity = { entityType: "invoice", entityId: 42, [getWidgetSelfHydrationKeyFieldName()]: 3 };
        expect(rendererEntry.readOpenEntityActionTarget({ type: "openEntity", ref: "call-1:invoice:0", entity })).toEqual({
            entityType: "invoice",
            entityId: "42",
            agentSpaceKey: 3,
        });
        expect(rendererEntry.readOpenEntityActionTarget({ type: "openEntity", ref: { $item: "entityRef" } }, { entity })).toEqual({
            entityType: "invoice",
            entityId: "42",
            agentSpaceKey: 3,
        });
        expect(rendererEntry.readOpenEntityActionTarget({ type: "openEntity", ref: { $item: "entityRef" }, entity })).toBeUndefined();
    });
    it("the root entry looks up the registered entity detail body of one entity type", () => {
        expect(rootEntry.getEntityDetailBody).toBe(getEntityDetailBody);
        const component = () => null;
        rootEntry.registerEntityDetailBodies([{ entityType: "entryExportsSpecType", component }]);
        expect(rootEntry.getEntityDetailBody("entryExportsSpecType")?.component).toBe(component);
    });
    it("./preview exports the ask-flow story body: a click on an ask button puts the entity into the composer strip", () => {
        expect(previewEntry.EntityMentionAskFlowStory).toBe(EntityMentionAskFlowStory);
        render(<previewEntry.EntityMentionAskFlowStory maxWidth={360}/>);
        const composer = screen.getByTestId("story-composer");
        expect(composer).not.toHaveTextContent("Altes Gasthaus Leve");
        fireEvent.click(screen.getByTestId("entity-mention-ask-8812"));
        expect(composer).toHaveTextContent("Altes Gasthaus Leve");
    });
    it("./contract exports the request a live update reads", () => {
        expect(contractEntry.buildChangedMessagesRequest).toBe(buildChangedMessagesRequest);
        const loaded = [
            { id: 100, indexInConversation: 0 },
            { id: 101, indexInConversation: 1 },
        ];
        expect(contractEntry.buildChangedMessagesRequest(loaded, 50)).toEqual({ highestKnownId: 101, fromIndex: 2, pageSize: 50 });
        expect(contractEntry.buildChangedMessagesRequest(null, 50)).toBeNull();
    });
    it("./chat exports the mount of a widget a page opens: only a deep-linkable widget, with openedByPage", () => {
        expect(chatEntry.ConversationDeepLinkedWidget).toBe(ConversationDeepLinkedWidget);
        const PageOpenedWidget = (props) => <output>{props.openedByPage ? "opened by page" : "in a message"}</output>;
        const testWidgets = clientTestingEntry.installTestConversationWidgets([
            { name: "SpecPageOpened", component: PageOpenedWidget, deepLinkable: true },
            { name: "SpecMessageOnly", component: PageOpenedWidget },
        ]);
        try {
            const { container } = render(<chatEntry.ConversationDeepLinkedWidget widgetName="SpecMessageOnly"/>);
            expect(container).toBeEmptyDOMElement();
            render(<chatEntry.ConversationDeepLinkedWidget widgetName="SpecPageOpened"/>);
            expect(screen.getByText("opened by page")).toBeInTheDocument();
        }
        finally {
            testWidgets.restore();
        }
    });
    it("./client/testing stores, reads and clears the pointer of a conversation's open canvas", () => {
        expect(clientTestingEntry.storeOpenCanvas).toBe(storeOpenCanvas);
        expect(clientTestingEntry.readStoredCanvas).toBe(readStoredCanvas);
        expect(clientTestingEntry.clearStoredCanvas).toBe(clearStoredCanvas);
        clientTestingEntry.storeOpenCanvas(42, {
            canvasId: "entityDetail",
            instanceId: "entityDetail:invoice:7",
            anchor: rendererEntry.messageAnchor(10),
            origin: "widgetClick",
            mountGeneration: 1,
        });
        expect(clientTestingEntry.readStoredCanvas(42)).toEqual({
            canvasId: "entityDetail",
            instanceId: "entityDetail:invoice:7",
            anchor: { kind: "message", messageId: 10 },
        });
        clientTestingEntry.clearStoredCanvas(42);
        expect(clientTestingEntry.readStoredCanvas(42)).toBeNull();
    });
});
