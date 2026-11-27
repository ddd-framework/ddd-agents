import { Text } from "../../provider/components";
import { useCallback, useMemo } from "react";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { canvasInstanceIdForEntityDetail, readOpenEntityActionTarget, } from "../../renderer/canvas/entity-detail-instance";
import { ComposedViewRenderer } from "../ComposedViewRenderer";
import { findMapNodeInMarkdown, readMapNodeKeyOfInstanceId } from "../components/map/map-full-screen-instance";
import { MapPresentationContext } from "../components/map/map-presentation-context";
import mapStyles from "../components/map/Map.module.scss";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";
/** The body reports nothing of its own: the inline block already reported this map to the model. */
const ignoreRenderReport = () => { };
/**
 * The `mapFullScreen` CANVAS BODY (`canvasBodies.tsx`): one `Map` node of the anchor
 * message, full screen — the map filling the pane beside the list of its points. It finds the node through the
 * message's `ComposedView` fences and the node key its instance id carries (`map-full-screen-instance.ts`), then
 * renders it through the same `ComposedViewRenderer` with the node's own tree, so its bindings (`$data`, `$from`,
 * `$source`, `$computed`, `$state` at its initial value) and its actions resolve exactly as inline; the
 * `MapPresentationContext` switches the node to its full-screen layout. A point's `openEntity` action opens the
 * `entityDetail` canvas on the same anchor message (it replaces this canvas, as a click on an inline point would).
 */
export const MapFullScreenCanvasBody = (props) => {
    const { canvas, anchorMessageMarkdown, agentSpaceKey } = props;
    const { openCanvas } = useConversationWidgetContext();
    const { onOpenEntity: openEntityOnHostRoute } = useAgentsEnvironment();
    const mapNodeKey = readMapNodeKeyOfInstanceId(canvas.instanceId);
    const mapTree = useMemo(() => {
        if (anchorMessageMarkdown === null || mapNodeKey === undefined)
            return undefined;
        const mapNodeInComposedView = findMapNodeInMarkdown(anchorMessageMarkdown, mapNodeKey);
        if (!mapNodeInComposedView)
            return undefined;
        // The map alone, with its tree's state, data, computed values and actions; the tree's findings were the inline
        // block's to report.
        return { ...mapNodeInComposedView.tree, title: null, view: mapNodeInComposedView.mapNode, findings: [] };
    }, [anchorMessageMarkdown, mapNodeKey]);
    const anchor = canvas.anchor;
    const onOpenEntity = useCallback((action, currentRow) => {
        const target = readOpenEntityActionTarget(action, currentRow);
        if (target === undefined)
            return;
        // The host opens the entity on a route of its own (`AgentsEnvironment.onOpenEntity`) instead of the canvas.
        if (openEntityOnHostRoute?.(target) === true)
            return;
        if (anchor.kind !== "message")
            return;
        openCanvas({
            canvasId: "entityDetail",
            instanceId: canvasInstanceIdForEntityDetail(target),
            anchor,
            origin: "widgetClick",
            justDelivered: false,
        });
    }, [openCanvas, anchor, openEntityOnHostRoute]);
    if (!mapTree) {
        return (<div className={mapStyles.fullScreenNotice} data-canvas-instance={canvas.instanceId} data-testid="map-full-screen-canvas-unavailable">
        <Text role="help" color="var(--agents-fg-light)">
          {"This map is no longer available."}
        </Text>
      </div>);
    }
    return (<div className={mapStyles.fullScreenBody} data-canvas-instance={canvas.instanceId} data-testid="map-full-screen-canvas">
      <MapPresentationContext.Provider value="fullScreen">
        <ComposedViewRenderer tree={mapTree} interactive isEmailRender={false} messageId={anchor.kind === "message" ? anchor.messageId : undefined} agentSpaceKey={agentSpaceKey} onReport={ignoreRenderReport} onOpenEntity={onOpenEntity} testId="map-full-screen-canvas-view"/>
      </MapPresentationContext.Provider>
    </div>);
};
