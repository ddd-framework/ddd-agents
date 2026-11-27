import { Button, SplitView } from "../provider/components";
import { classNames } from "../provider/class-names";
import React, { useRef, useState } from "react";
import { useCanvasViable } from "../widgets/CanvasLauncherWidget/use-canvas-viable";
import { CANVAS_BOXED_INSET_PX, CANVAS_MIN_WIDTH_PX, CHAT_COLUMN_MIN_WIDTH_PX, DEFAULT_CANVAS_WIDTH_PERCENT, canvasBehaviourOf, canvasWidthPercentOf, } from "../renderer/canvas/canvas-behaviours";
import { ConversationViewBody } from "./ConversationViewBody/ConversationViewBody";
import { CanvasPane } from "./CanvasPane/CanvasPane";
import { CanvasResizeHandle } from "./CanvasResizeHandle/CanvasResizeHandle";
import { useCanvasShellTransitions } from "./use-canvas-shell-transitions";
import { useConversationWidgetContext } from "../renderer/ConversationWidgetContext";
import classes from "./Conversation.module.scss";
export const ConversationView = (props) => {
    const { enableCanvasToggle, boxCanvasBelowTopBar, onSendMessage, conversationId, messages } = props;
    const { canvas, lastClosedCanvas, closeCanvas, reopenLastClosedCanvas, resetCanvasForNewTurn } = useConversationWidgetContext();
    const canvasViable = useCanvasViable();
    // The canvas is a pointer in the chat's slice; its geometry comes from its behaviour (`canvas-behaviours.ts`).
    const canvasBehaviour = canvas ? (canvasBehaviourOf(canvas.canvasId) ?? null) : null;
    const isCanvasOpen = canvas !== null;
    // The conversation switch clears the canvas; the wide → narrow crossing closes a `narrowViewport: "none"` canvas.
    useCanvasShellTransitions({ conversationId, canvasViable });
    // A new user message starts a new turn — the previous turn's canvas is now stale, so tear it down AND drop the
    // divider FAB (the reset clears `canReopenDrawer`). Runs before the reply arrives, so a fresh canvas opened by the
    // new reply is unaffected. A PINNED canvas (a work surface for the whole conversation, not one
    // turn's result) survives — that is the only difference to `resetCanvas`.
    const handleSendMessage = (message, options) => {
        resetCanvasForNewTurn();
        onSendMessage(message, options);
    };
    // The canvas pane starts at the width its behaviour declares; the customer can pull its left edge (`CanvasResizeHandle`).
    // The split reads the width from `--agents-canvas-width-set` (a percentage, or the clamped pull in px), so a drag moves the edge without a
    // render. The width of the last shown canvas is kept while the pane slides out. The divider toggle + slide derive from it.
    const lastCanvasWidthPercentRef = useRef(DEFAULT_CANVAS_WIDTH_PERCENT);
    if (canvasBehaviour)
        lastCanvasWidthPercentRef.current = canvasWidthPercentOf(canvasBehaviour);
    const canvasWidthPercent = lastCanvasWidthPercentRef.current;
    const boxCanvas = !!boxCanvasBelowTopBar && canvasViable;
    const canvasInset = boxCanvas ? CANVAS_BOXED_INSET_PX : 0;
    const splitViewRootRef = useRef(null);
    // The pull belongs to the canvas it was made on: another canvas starts at its own default split.
    const lastCanvasIdRef = useRef(null);
    if (canvas)
        lastCanvasIdRef.current = canvas.canvasId;
    const [pulledCanvas, setPulledCanvas] = useState(null);
    const pulledCanvasWidth = pulledCanvas?.canvasId === lastCanvasIdRef.current ? pulledCanvas.width : null;
    const setPulledCanvasWidth = (width) => setPulledCanvas(width !== null && lastCanvasIdRef.current ? { canvasId: lastCanvasIdRef.current, width } : null);
    const canvasResizable = canvasBehaviour?.layout === "split" && canvasViable && !props.isCompact;
    const splitViewRootStyle = {
        "--agents-canvas-width-set": pulledCanvasWidth === null
            ? `${canvasWidthPercent}%`
            : `clamp(${CANVAS_MIN_WIDTH_PX + canvasInset * 2}px, ${pulledCanvasWidth}px, calc(100% - ${CHAT_COLUMN_MIN_WIDTH_PX}px))`,
        "--agents-canvas-inset": `${canvasInset}px`,
    };
    // The canvas toggle button on the divider: closes the open canvas (the customer's own close), re-opens the last
    // closed one.
    const toggleCanvas = () => {
        if (canvas)
            closeCanvas(true);
        else
            reopenLastClosedCanvas();
    };
    // On a narrow viewport the divider (and its toggle) does not exist: the canvas is a full-screen overlay. The canvases
    // whose widgets render nothing inline would have no way back after a close, so the shell offers its own re-open
    // control over the chat, fed by `lastClosedCanvas`, independent of transcript windowing.
    const showsNarrowReopenControl = !!enableCanvasToggle && !canvasViable && !isCanvasOpen && lastClosedCanvas !== null;
    if (props.disableSplitView) {
        return <ConversationViewBody {...props}/>;
    }
    return (<div ref={splitViewRootRef} className={classes.splitViewRoot} style={splitViewRootStyle}>
      {showsNarrowReopenControl ? (<div className={classes.canvasReopenControl}>
          <Button variant="muted" icon="maximize" iconPosition={"left"} rounded blurred size="small" onClick={reopenLastClosedCanvas}>
            Re-open canvas
          </Button>
        </div>) : null}
      <SplitView isSideOpen={isCanvasOpen} coversBelowWidth={850} 
    // The canvas toggle button on the divider: closes the open canvas, or re-opens the last closed one. Shown only
    // while a canvas is open or one was closed — otherwise there's nothing to toggle.
    sideToggle={enableCanvasToggle && !canvasResizable && (isCanvasOpen || lastClosedCanvas !== null)
            ? {
                onToggle: toggleCanvas,
                // Centered vertically on the divider.
                verticalPositionPercent: 50,
                // The chat's own re-open control is shown: the split view adds no second one while it covers.
                mainPaneShowsReopen: showsNarrowReopenControl,
            }
            : undefined} main={{
            width: "100%",
            narrowedWidth: "calc(100% - var(--agents-canvas-width))",
            // The pane's default inline background (var(--agents-neutral-000)) would occlude a host-painted background layer.
            background: props.backgroundPaintedByHost ? "transparent" : undefined,
            content: <ConversationViewBody {...props} onSendMessage={handleSendMessage}/>,
        }} side={{
            width: "var(--agents-canvas-width)",
            narrowedWidth: "var(--agents-canvas-width)",
            content: (
            // Boxed only while side-split: below 850px the canvas is a full-screen overlay and stays full-bleed.
            <div className={classNames(classes.splitViewPane, boxCanvas ? classes.splitViewPaneBoxed : "")}>
              <CanvasPane messages={messages} boxed={boxCanvas}/>
            </div>),
        }}/>
      {canvasResizable ? (<CanvasResizeHandle rootRef={splitViewRootRef} inset={canvasInset} width={pulledCanvasWidth} onWidthChange={setPulledCanvasWidth} onClose={() => closeCanvas(true)}/>) : null}
    </div>);
};
