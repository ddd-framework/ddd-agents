import { Icon, InteractiveSurface, Text, type AgentsIconName } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import { useWidgetImageRender } from "../../image-render/WidgetImageRenderContext";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import React, { ReactNode, useEffect, useRef } from "react";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { ConversationWidgetAgentSpaceHeader } from "../../renderer/_agent-space/ConversationWidgetAgentSpaceHeader";
import type { CanvasId } from "../../renderer/canvas/canvas-behaviours";
import {
  CanvasAnchor,
  sameCanvasIdentity,
} from "../../renderer/canvas/canvas-state";
import { useCanvasViable } from "./use-canvas-viable";
import styles from "./CanvasWidgetCard.module.scss";

/**
 * The canvas a card opens: which canvas, which instance, anchored on which message. The body comes from
 * `canvasBodies` and re-reads its payload from the anchor message — the card carries no rendered node. Layout
 * (width, narrow-viewport rule, full bleed) is the canvas's behaviour (`canvas-behaviours.ts`), not a card prop.
 */
export interface CanvasWidgetCardCanvas {
  canvasId: CanvasId;
  instanceId: string;
  anchor: CanvasAnchor;
}

export interface CanvasWidgetCardProps {
  /**
   * Headline shown on the inline card (e.g. "Search results for 'X'"). Rendered through the component library's `Text`, so
   * it auto-translates: keep dynamic values out of the string and pass them via `titleReplacements` (`%token%`).
   */
  title: string;
  /** `%token%` → value replacements for `title`, so an interpolated headline stays one stable translation key. */
  titleReplacements?: Record<string, string | number>;
  /** False when `title` is a business/user value that must render as-is (not a translation key). Default true. */
  translateTitle?: boolean;
  /** Optional second line (e.g. "5 conversations", a filter summary). Auto-translates — interpolate via `subtitleReplacements`. */
  subtitle?: string;
  /** `%token%` → value replacements for `subtitle`, so an interpolated second line stays one stable translation key. */
  subtitleReplacements?: Record<string, string | number>;
  /** False when `subtitle` is a business/user value that must render as-is (not a translation key). Default true. */
  translateSubtitle?: boolean;
  /** Optional leading icon (a glyph hinting what the canvas holds). Falls back to a generic panel glyph. */
  icon?: AgentsIconName;
  /**
   * A preview of the picture the canvas opens with. Set → the RICH card: the thumbnail replaces the icon badge, the
   * title gets more weight, and the hover is the component library's `GlobalStateComponent` layer (e.g. an app's closing summary
   * card with a hero picture).
   */
  thumbnail?: ReactNode;
  /** True when the host message was delivered live right now — auto-opens the canvas once. Never true after a reload or on history replay. */
  justDelivered?: boolean;
  /**
   * The canvas this card opens (`canvasForMessageWidget`). Undefined while the widget does not know its message (the
   * reply is still streaming, a composer echo): the card renders but opens nothing.
   */
  canvas?: CanvasWidgetCardCanvas;
  /**
   * The other agent space the canvas shows, when it is not the active one. The named card shows that agent space's chip on
   * a row above it: floating over the card's corner, the chip would cover the open affordance, and inside the card
   * (a `<button>`) its focusable popover trigger would nest one control in another. A `preview` names the agent space
   * itself, so the narrow viewport's preview branch adds nothing.
   */
  anotherAgentSpaceKey?: number;
  /**
   * The real widget content (the lightweight inline list). Rendered directly in the chat on a NARROW viewport (no side
   * canvas) so the user still sees the data; on a wide viewport the content lives only in the side canvas and this is
   * not rendered — the card is just the card.
   */
  preview?: ReactNode;
  /**
   * The widget's root test id. The named card carries it, its open control `<testId>-open-canvas`; with a `preview`
   * the preview carries the root id itself and only the open control is marked.
   */
  testId?: string;
}

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
export const CanvasWidgetCard = ({
  title,
  titleReplacements,
  translateTitle = true,
  subtitle,
  subtitleReplacements,
  translateSubtitle = true,
  icon,
  thumbnail,
  justDelivered = false,
  canvas,
  anotherAgentSpaceKey,
  preview,
  testId,
}: CanvasWidgetCardProps) => {
  const { openCanvas, canvas: openCanvasState } = useConversationWidgetContext();
  const canvasViable = useCanvasViable();
  const openedRef = useRef(false);
  const capture = useWidgetImageRender();
  const isEmailRender = useIsEmailWidgetRender();

  // Auto-open once per mount on a just-delivered message; the reducer applies the opening rule.
  useEffect(() => {
    if (isEmailRender || capture !== null || openedRef.current || !canvas || !justDelivered) return;
    openedRef.current = true;
    openCanvas({ ...canvas, origin: "deliveredInMessage", justDelivered: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [justDelivered, canvas?.instanceId]);

  const open = () => {
    if (canvas) openCanvas({ ...canvas, origin: "cardClick", justDelivered: false });
  };

  // The inline preview is suppressed while THIS card's canvas is open.
  const isThisCanvasOpen =
    canvas !== undefined && openCanvasState !== null && sameCanvasIdentity(openCanvasState, canvas);

  // The card: icon badge + title + subtitle + open affordance. Opens the canvas — as the side split on a wide
  // viewport, as the full-screen overlay on a narrow one.
  const subtitleText = subtitle ? (
    <Text
      role="caption"
      color="var(--agents-fg-light)"
      ellipsis
      translate={translateSubtitle}
      replacements={subtitleReplacements}
    >
      {subtitle}
    </Text>
  ) : null;

  // Every card shape: GlobalStateComponent is the card surface and draws the hover layer (it takes no pointer events);
  // the native button inside keeps the click and keyboard focus.
  const openCanvasTestId = testId ? `${testId}-open-canvas` : undefined;
  const cardTestId = anotherAgentSpaceKey === undefined ? testId : undefined;
  const renderCard = (cardContent: ReactNode, isRich = false, surfaceTestId?: string) => (
    <InteractiveSurface
      hoverable
      className={classNames(styles.cardSurface, isRich && styles.richCardSurface)}
      testId={surfaceTestId}
    >
      <button
        type="button"
        className={classNames(styles.cardButton, isRich && styles.richCardButton)}
        onClick={open}
        data-testid={openCanvasTestId}
      >
        {cardContent}
      </button>
    </InteractiveSurface>
  );

  const openAffordance = (
    <span className={styles.openAffordance}>
      <Icon className={styles.openIcon} name="arrowRight" />
    </span>
  );

  // The rich card: thumbnail + title + caption.
  const card = thumbnail
    ? renderCard(
        <>
          <span className={styles.thumbnail}>{thumbnail}</span>
          <div className={styles.body}>
            <Text
              role="body"
              weight="bold"
              ellipsis
              translate={translateTitle}
              replacements={titleReplacements}
            >
              {title}
            </Text>
            {subtitleText}
          </div>
          <Icon className={styles.richOpenIcon} name="arrowRight" />
        </>,
        true,
        cardTestId,
      )
    : renderCard(
        <>
          <span className={styles.leadingIconBadge}>
            <Icon name={icon ?? DEFAULT_ICON} />
          </span>
          <div className={styles.body}>
            <Text
              role="help"
              weight="bold"
              ellipsis
              translate={translateTitle}
              replacements={titleReplacements}
            >
              {title}
            </Text>
            {subtitleText}
          </div>
          {openAffordance}
        </>,
        false,
        cardTestId,
      );

  if (isEmailRender || capture !== null) {
    if (!preview) throw new Error("WIDGET_REQUIRES_STATIC_PREVIEW");
    return <>{preview}</>;
  }

  // Narrow viewport WITH an inline render: show the content (so the user sees the data without a side panel), plus a
  // card-styled "Open in canvas" affordance to open the full view over the chat. The inline content is suppressed while
  // this canvas is open, so a self-fetching widget never runs two instances against one shared Redux slice.
  if (!canvasViable && preview) {
    return (
      <div className={styles.mobile}>
        {isThisCanvasOpen ? null : preview}
        {renderCard(
          <>
            <span className={styles.leadingIconBadge}>
              <Icon name="maximize" />
            </span>
            <div className={styles.body}>
              <Text role="help" weight="bold" ellipsis>
                Open in canvas
              </Text>
            </div>
            {openAffordance}
          </>,
        )}
      </div>
    );
  }

  // Wide viewport, and narrow with no inline render (the canvas is the only surface): the named card.
  if (anotherAgentSpaceKey === undefined) return card;
  return (
    <div className={styles.cardOfAnotherBusiness} data-testid={testId}>
      <ConversationWidgetAgentSpaceHeader agentSpaceKey={anotherAgentSpaceKey} inFlow />
      {card}
    </div>
  );
};
