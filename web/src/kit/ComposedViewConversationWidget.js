import { useCallback, useMemo, useState } from "react";
import { useIsEmailWidgetRender } from "../renderer/ConversationWidgetRenderContext";
import { useConversationWidgetContext } from "../renderer/ConversationWidgetCallbacksContext";
import { readWidgetAgentSpaceKeyAttribute } from "../renderer/_agent-space/widget-agent-space-key-attribute";
import { messageAnchor } from "../renderer/canvas/canvas-state";
import { canvasInstanceIdForEntityDetail, readOpenEntityActionTarget, } from "../renderer/canvas/entity-detail-instance";
import { parseComposedViewTree, } from "../renderer/widget-tree/composed-view-tree";
import { useReportWidgetRenderStatus, } from "../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "./ComposedViewRenderer";
import { useAgentsEnvironment } from "../provider/AgentsProvider";
/**
 * The composable widget kit: the model composes a TREE of kit components — layout, text, data,
 * media, inputs — with local state, computed values and a closed list of actions, in one ```widget fence of type
 * `ComposedView`. The payload is read into typed objects and checked against the generated node schema
 * (`widget-tree/composed-view-tree.ts`, the twin of the backend reader); what is invalid is dropped and reported, the
 * rest renders. Inputs change local state only (no chat turn, no write), so a replay stays usable; the e-mail capture
 * freezes them at their initial state.
 *
 * The kit's components register themselves by folder (`components/**\/*.composed.tsx`); this widget only parses,
 * renders, reports and opens the `entityDetail` canvas for an `openEntity` action whose reference the backend resolved
 * (a reference still unresolved opens nothing; without a message id — a streaming reply — nothing opens either).
 */
export const ComposedViewConversationWidget = (props) => {
    const { payload, interactive = false, messageId } = props;
    const agentSpaceKey = readWidgetAgentSpaceKeyAttribute(props);
    const isEmailRender = useIsEmailWidgetRender();
    const tree = useMemo(() => parseComposedViewTree(payload), [payload]);
    const [blockReport, setBlockReport] = useState({ status: "pending" });
    const onReport = useCallback((entry) => setBlockReport(entry), []);
    const { openCanvas } = useConversationWidgetContext();
    const { onOpenEntity: openEntityOnHostRoute } = useAgentsEnvironment();
    const numericMessageId = messageId === undefined ? Number.NaN : Number(messageId);
    const onOpenEntity = useCallback((action, currentRow) => {
        const target = readOpenEntityActionTarget(action, currentRow);
        if (target === undefined)
            return;
        // The host opens the entity on a route of its own (`AgentsEnvironment.onOpenEntity`) instead of the canvas.
        if (openEntityOnHostRoute?.(target) === true)
            return;
        if (!Number.isFinite(numericMessageId))
            return;
        openCanvas({
            canvasId: "entityDetail",
            instanceId: canvasInstanceIdForEntityDetail(target),
            anchor: messageAnchor(numericMessageId),
            origin: "widgetClick",
            justDelivered: false,
        });
    }, [openCanvas, numericMessageId, openEntityOnHostRoute]);
    useReportWidgetRenderStatus(blockReport.status, blockReport.code, blockReport.detail);
    return (<ComposedViewRenderer tree={tree} interactive={interactive} isEmailRender={isEmailRender} messageId={messageId} agentSpaceKey={agentSpaceKey} onReport={onReport} onOpenEntity={onOpenEntity}/>);
};
