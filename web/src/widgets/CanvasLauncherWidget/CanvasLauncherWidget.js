import { Icon, InteractiveSurface, Text } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import { useWidgetImageRender } from "../../image-render/WidgetImageRenderContext";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import React, { useEffect, useRef } from "react";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { ConversationWidgetAgentSpaceHeader } from "../../renderer/_agent-space/ConversationWidgetAgentSpaceHeader";
import { sameCanvasIdentity, } from "../../renderer/canvas/canvas-state";
import { useCanvasViable } from "./use-canvas-viable";
import styles from "./CanvasLauncherWidget.module.scss";
/** Generic fallback glyph so the badge never renders empty when a widget omits its own icon. */
const DEFAULT_ICON = "maximize";
/**
 * The standard inline representation of a CANVAS widget in the conversation — ONE component shared by every canvas
 * widget (web search, conversation search, and every list, gallery or summary canvas an app's widgets open).
 *
 * Wide viewport: a compact card (icon badge + title + subtitle + "open" affordance) that opens the canvas as the side
 * split, auto-opening once on a just-delivered message. The opening rule (`decideCanvasOpen`) decides in the reducer:
 * replay, the narrow-viewport rule of the canvas's behaviour and the customer's veto all live there.
 *
 * Narrow viewport (no side split, the canvas opens as a full-screen overlay), two shapes:
 *  - with a `preview`: the real inline content is rendered in the chat so the user sees the data, plus an "Open in
 *    canvas" affordance to open the full view over the chat. Tap to open — a live delivery is not a takeover.
 *  - without a `preview` (the canvas is the widget's only surface, behaviour `narrowViewport: "overlay"`): the same
 *    named card as on the wide viewport, so closing the canvas leaves a card to re-open.
 */
export const CanvasLauncherWidget = ({ title, titleReplacements, translateTitle = true, subtitle, subtitleReplacements, translateSubtitle = true, icon, leading, thumbnail, justDelivered = false, canvas, anotherAgentSpaceKey, preview, testId, }) => {
    const { openCanvas, canvas: openCanvasState } = useConversationWidgetContext();
    const canvasViable = useCanvasViable();
    const openedRef = useRef(false);
    const capture = useWidgetImageRender();
    const isEmailRender = useIsEmailWidgetRender();
    // Auto-open once per mount on a just-delivered message; the reducer applies the opening rule.
    useEffect(() => {
        if (isEmailRender || capture !== null || openedRef.current || !canvas || !justDelivered)
            return;
        openedRef.current = true;
        openCanvas({ ...canvas, origin: "deliveredInMessage", justDelivered: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [justDelivered, canvas?.instanceId]);
    const open = () => {
        if (canvas)
            openCanvas({ ...canvas, origin: "widgetClick", justDelivered: false });
    };
    // The inline preview is suppressed while THIS card's canvas is open.
    const isThisCanvasOpen = canvas !== undefined && openCanvasState !== null && sameCanvasIdentity(openCanvasState, canvas);
    // The card: icon badge + title + subtitle + open affordance. Opens the canvas — as the side split on a wide
    // viewport, as the full-screen overlay on a narrow one.
    const subtitleText = subtitle ? (<Text role="caption" color="var(--agents-fg-light)" ellipsis translate={translateSubtitle} replacements={subtitleReplacements}>
      {subtitle}
    </Text>) : null;
    // Every card shape: GlobalStateComponent is the card surface and draws the hover layer (it takes no pointer events);
    // the native button inside keeps the click and keyboard focus.
    const openCanvasTestId = testId ? `${testId}-open-canvas` : undefined;
    const cardTestId = anotherAgentSpaceKey === undefined ? testId : undefined;
    const renderCard = (cardContent, isRich = false, surfaceTestId) => (<InteractiveSurface hoverable className={classNames(styles.cardSurface, isRich && styles.richCardSurface)} testId={surfaceTestId}>
      <button type="button" className={classNames(styles.cardButton, isRich && styles.richCardButton)} onClick={open} data-testid={openCanvasTestId}>
        {cardContent}
      </button>
    </InteractiveSurface>);
    const openAffordance = (<span className={styles.openAffordance}>
      <Icon className={styles.openIcon} name="arrowRight"/>
    </span>);
    // The rich card: thumbnail + title + caption.
    const card = thumbnail
        ? renderCard(<>
          <span className={styles.thumbnail}>{thumbnail}</span>
          <div className={styles.body}>
            <Text role="body" weight="bold" ellipsis translate={translateTitle} replacements={titleReplacements}>
              {title}
            </Text>
            {subtitleText}
          </div>
          <Icon className={styles.richOpenIcon} name="arrowRight"/>
        </>, true, cardTestId)
        : renderCard(<>
          <span className={styles.leadingIconBadge}>
            {leading ?? <Icon name={icon ?? DEFAULT_ICON}/>}
          </span>
          <div className={styles.body}>
            <Text role="help" weight="bold" ellipsis translate={translateTitle} replacements={titleReplacements}>
              {title}
            </Text>
            {subtitleText}
          </div>
          {openAffordance}
        </>, false, cardTestId);
    if (isEmailRender || capture !== null) {
        if (!preview)
            throw new Error("WIDGET_REQUIRES_STATIC_PREVIEW");
        return <>{preview}</>;
    }
    // Narrow viewport WITH an inline render: show the content (so the user sees the data without a side panel), plus a
    // card-styled "Open in canvas" affordance to open the full view over the chat. The inline content is suppressed while
    // this canvas is open, so a self-fetching widget never runs two instances against one shared Redux slice.
    if (!canvasViable && preview) {
        return (<div className={styles.mobile}>
        {isThisCanvasOpen ? null : preview}
        {renderCard(<>
            <span className={styles.leadingIconBadge}>
              <Icon name="maximize"/>
            </span>
            <div className={styles.body}>
              <Text role="help" weight="bold" ellipsis>
                Open in canvas
              </Text>
            </div>
            {openAffordance}
          </>)}
      </div>);
    }
    // Wide viewport, and narrow with no inline render (the canvas is the only surface): the named card.
    if (anotherAgentSpaceKey === undefined)
        return card;
    return (<div className={styles.cardOfAnotherBusiness} data-testid={testId}>
      <ConversationWidgetAgentSpaceHeader agentSpaceKey={anotherAgentSpaceKey} inFlow/>
      {card}
    </div>);
};
