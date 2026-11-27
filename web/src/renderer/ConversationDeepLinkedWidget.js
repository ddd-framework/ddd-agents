import React, { useMemo } from "react";
import { resolveDeepLinkableWidget } from "./resolve-deep-linkable-widget";
import { ConversationWidgetFeatureGate } from "./_access/ConversationWidgetFeatureGate";
import { ConversationWidgetErrorBoundary } from "./ConversationWidgetErrorBoundary";
import { buildWidgetAgentSpaceKeyProps } from "./_agent-space/widget-agent-space-key-attribute";
/**
 * Mounts ONE registered widget outside the message list — the customer opened it from a page (`?agentWidget=`). The
 * widget receives `openedByPage` (plus `interactive` / `justDelivered`), so a canvas-opening widget (an app's editor
 * widget, `ExampleWidget`) opens its canvas with origin `openedByPage` and a page anchor; nothing is written to the
 * conversation, no message and no signal. (A canvas that was open before a reload comes back through
 * `CanvasRestoreOnMount`, not through this mount.)
 *
 * Rendered with the entry's feature gate and the shared error boundary, but WITHOUT the viewport windowing of
 * {@link ConversationWidgetHost}: there is no message to scroll past here, and a windowed wrapper would unmount a
 * zero-height widget (these widgets render `null` and live entirely in the canvas), taking its canvas with it.
 */
export const ConversationDeepLinkedWidget = ({ widgetName, agentSpaceKey }) => {
    const entry = useMemo(() => resolveDeepLinkableWidget(widgetName), [widgetName]);
    if (!entry)
        return null;
    const { component: Component, featureFlag, upsellTrigger } = entry;
    const widget = <Component fresh interactive justDelivered openedByPage {...buildWidgetAgentSpaceKeyProps(agentSpaceKey)}/>;
    return (<ConversationWidgetErrorBoundary widgetName={entry.name}>
      {featureFlag ? (<ConversationWidgetFeatureGate featureFlag={featureFlag} UpsellTrigger={upsellTrigger} agentSpaceKeyAttribute={agentSpaceKey}>
          {widget}
        </ConversationWidgetFeatureGate>) : (widget)}
    </ConversationWidgetErrorBoundary>);
};
