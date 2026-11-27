import { useWidgetImageRender } from "../../image-render/WidgetImageRenderContext";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import React from "react";
import { CanvasLauncherWidget } from "./CanvasLauncherWidget";
/**
 * The single switch shared by every canvas widget. It owns the one decision — is this a CANVAS widget at all: the
 * agent forced it (`display`) or the content exceeds the widget's inline limit. Small content renders inline in the
 * chat. A canvas widget hands off to {@link CanvasLauncherWidget}, which itself decides the viewport-dependent shape: on a
 * wide viewport a clickable card (opens the side split), on a narrow viewport the inline render plus an "Open in
 * canvas" CTA (opens over the chat). Centralising this here keeps all widgets consistent and free of the branch.
 */
export const CanvasWidgetSwitch = ({ display, exceedsInlineLimit = false, title, titleReplacements, subtitle, subtitleReplacements, translateSubtitle, icon, justDelivered = false, canvas, anotherAgentSpaceKey, renderInline, testId, }) => {
    const capture = useWidgetImageRender();
    const isEmailRender = useIsEmailWidgetRender();
    if (isEmailRender || capture !== null)
        return <>{renderInline({ isWidgetPreview: false })}</>;
    const isCanvasWidget = display === "canvas" || (display !== "inline" && exceedsInlineLimit);
    if (!isCanvasWidget)
        return <>{renderInline({ isWidgetPreview: false })}</>;
    return (<CanvasLauncherWidget title={title} titleReplacements={titleReplacements} subtitle={subtitle} subtitleReplacements={subtitleReplacements} translateSubtitle={translateSubtitle} icon={icon} justDelivered={justDelivered} canvas={canvas} anotherAgentSpaceKey={anotherAgentSpaceKey} preview={renderInline({ isWidgetPreview: true })} testId={testId}/>);
};
