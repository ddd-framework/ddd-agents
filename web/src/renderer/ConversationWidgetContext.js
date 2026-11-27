import React, { useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useAgentsEnvironment, useAgentsStateDispatch, useAgentsStateSelector } from "../provider/AgentsProvider";
import { createAgentsChatStore } from "../chat/store/create-agents-chat-store";
import { useAgentsChatSelector } from "../chat/store/use-agents-chat-store";
import { CanvasViabilityContext, readCanvasViable } from "../widgets/CanvasLauncherWidget/use-canvas-viable";
import { clearStoredCanvas, storeOpenCanvas } from "./canvas/canvasStorage";
import { ConversationWidgetContext, } from "./ConversationWidgetCallbacksContext";
// The callbacks, their context and the chat-free hosts live in `ConversationWidgetCallbacksContext.tsx`; this module
// adds the provider that reads the chat's state (the package chat store or an app's partition) and re-exports the rest.
export * from "./ConversationWidgetCallbacksContext";
// Number of mounted agentic chat surfaces (widget, full-page chat, dashboard). Drives the app's "a chat surface is
// open" action (`buildChatSurfaceOpenChangedAction`, e.g. a flag of the app's own flows): true while ≥1 is open.
// Module-level so multiple providers share one count.
let agenticChatMountCount = 0;
const selectCanvas = (state) => state.canvas;
const selectLastClosedCanvas = (state) => state.lastClosedCanvas;
const selectAiThinking = (state) => state.aiThinking;
const selectRunState = (state) => state.conversation?.runState;
const selectMessages = (state) => state.messages;
/** The provider over the package chat store of the chat. */
const ChatStoreConversationWidgetProvider = (props) => {
    const { chatStore, ...bodyProps } = props;
    const canvas = useAgentsChatSelector(chatStore, selectCanvas);
    const lastClosedCanvas = useAgentsChatSelector(chatStore, selectLastClosedCanvas);
    const isConversationRunning = useAgentsChatSelector(chatStore, selectAiThinking);
    const runState = useAgentsChatSelector(chatStore, selectRunState);
    const messages = useAgentsChatSelector(chatStore, selectMessages);
    const chatState = useMemo(() => ({
        canvas,
        lastClosedCanvas,
        isConversationRunning,
        runState,
        messages,
        dispatchCanvasEvent: chatStore.dispatch,
    }), [canvas, lastClosedCanvas, isConversationRunning, runState, messages, chatStore]);
    return <ConversationWidgetProviderBody {...bodyProps} chatState={chatState}/>;
};
/** The provider over a partition of the older chat state (the AI-Extensions chat, stories, specs). */
const SliceConversationWidgetProvider = (props) => {
    const { sliceInstance, ...bodyProps } = props;
    const dispatch = useAgentsStateDispatch();
    // One selector per field, not one returning an object, so an unrelated store change does not re-render every widget
    // through a fresh object identity.
    const canvas = useAgentsStateSelector((state) => sliceInstance.selectState(state).canvas);
    const lastClosedCanvas = useAgentsStateSelector((state) => sliceInstance.selectState(state).lastClosedCanvas);
    const isConversationRunning = useAgentsStateSelector((state) => sliceInstance.selectState(state).aiThinking);
    const runState = useAgentsStateSelector((state) => sliceInstance.selectState(state).conversation?.runState);
    const messages = useAgentsStateSelector((state) => sliceInstance.selectState(state).messages);
    const dispatchCanvasEvent = useCallback((event) => {
        const { actions } = sliceInstance;
        if (event.type === "canvasOpened")
            dispatch(actions.openCanvas(event.request));
        else if (event.type === "canvasClosed")
            dispatch(actions.closeCanvas({ byCustomer: event.byCustomer }));
        else if (event.type === "canvasResetForNewTurn")
            dispatch(actions.resetCanvasForNewTurn());
        else if (event.type === "canvasCleared")
            dispatch(actions.clearCanvas());
        else
            dispatch(actions.setCanvasCreatedEntityId(event.created));
    }, [dispatch, sliceInstance]);
    const chatState = useMemo(() => ({ canvas, lastClosedCanvas, isConversationRunning, runState, messages, dispatchCanvasEvent }), [canvas, lastClosedCanvas, isConversationRunning, runState, messages, dispatchCanvasEvent]);
    return <ConversationWidgetProviderBody {...bodyProps} chatState={chatState}/>;
};
/** The provider over a chat store of its own — a host that brings neither a chat store nor a partition (a story). */
const OwnChatStoreConversationWidgetProvider = (props) => {
    const [ownChatStore] = useState(createAgentsChatStore);
    return <ChatStoreConversationWidgetProvider {...props} chatStore={ownChatStore}/>;
};
/**
 * The widget callbacks of one chat, the canvas model included. Reads the chat's state from the package chat store when
 * the host passes one (`chatStore`), else from a partition of the app's own chat state (`sliceInstance`, or the
 * environment's `defaultChatStatePartition` — host application: the AI-Extensions chat), else from a store of its own.
 */
export const ConversationWidgetProvider = (props) => {
    const { sliceInstance, chatStore, ...bodyProps } = props;
    const { defaultChatStatePartition } = useAgentsEnvironment();
    const statePartition = sliceInstance ?? defaultChatStatePartition;
    if (chatStore)
        return <ChatStoreConversationWidgetProvider {...bodyProps} chatStore={chatStore}/>;
    if (statePartition)
        return <SliceConversationWidgetProvider {...bodyProps} sliceInstance={statePartition}/>;
    return <OwnChatStoreConversationWidgetProvider {...bodyProps}/>;
};
const ConversationWidgetProviderBody = ({ children, callbacks, hostHasCanvasSurface = true, chatState, }) => {
    const dispatch = useAgentsStateDispatch();
    const { buildChatSurfaceOpenChangedAction } = useAgentsEnvironment();
    const { canvas, lastClosedCanvas, isConversationRunning, runState, messages } = chatState;
    const isConversationClosed = runState === "COMPLETED" || runState === "CANCELLED";
    const dispatchCanvasEventRef = useRef(chatState.dispatchCanvasEvent);
    dispatchCanvasEventRef.current = chatState.dispatchCanvasEvent;
    const hostHasCanvasSurfaceRef = useRef(hostHasCanvasSurface);
    hostHasCanvasSurfaceRef.current = hostHasCanvasSurface;
    // The width of the chat's own container when the chat measures it (`AgentChatController`), else the viewport.
    const canvasViability = useContext(CanvasViabilityContext);
    const canvasViabilityRef = useRef(canvasViability);
    canvasViabilityRef.current = canvasViability;
    // An agentic chat surface is open: the app hears it through its action (e.g. a flag every connect/create/verify flow
    // launched from inside the chat reads to stay in-widget instead of navigating away).
    // Set here (not at the connect button) so it also covers the verify path, where there is no connect click.
    // Ref-counted so concurrently-mounted chat surfaces don't clear each other's flag.
    const buildChatSurfaceOpenChangedActionRef = useRef(buildChatSurfaceOpenChangedAction);
    buildChatSurfaceOpenChangedActionRef.current = buildChatSurfaceOpenChangedAction;
    useEffect(() => {
        agenticChatMountCount += 1;
        if (agenticChatMountCount === 1 && buildChatSurfaceOpenChangedActionRef.current) {
            dispatch(buildChatSurfaceOpenChangedActionRef.current(true));
        }
        return () => {
            agenticChatMountCount -= 1;
            if (agenticChatMountCount === 0 && buildChatSurfaceOpenChangedActionRef.current) {
                dispatch(buildChatSurfaceOpenChangedActionRef.current(false));
            }
        };
    }, [dispatch]);
    // Mirror the latest callbacks in a ref so the exposed functions stay stable across
    // renders even if the parent passes a fresh `callbacks` object every render.
    const callbacksRef = useRef(callbacks);
    callbacksRef.current = callbacks;
    // Composer-widget ownership — controlled when the host supplies `composerWidgets` (the builder page, click-bridge
    // F), otherwise uncontrolled: the provider owns the state so a descendant (e.g. an app's editor drawer) can
    // mutate it directly. See ConversationWidgetCallbacks.
    const isComposerWidgetsControlled = callbacks.composerWidgets !== undefined;
    const [internalComposerWidgets, setInternalComposerWidgets] = useState([]);
    const composerWidgets = isComposerWidgetsControlled
        ? callbacks.composerWidgets
        : internalComposerWidgets;
    // Mirrors the latest resolved composerWidgets + controlled flag so the mutator callbacks below can compute the
    // next array off REAL current state while keeping stable identities across renders ([] deps) — same trick as
    // `callbacksRef` above.
    const composerWidgetsStateRef = useRef({ composerWidgets, isComposerWidgetsControlled });
    composerWidgetsStateRef.current = { composerWidgets, isComposerWidgetsControlled };
    const publishComposerWidgets = useCallback((next) => {
        if (composerWidgetsStateRef.current.isComposerWidgetsControlled) {
            callbacksRef.current.onComposerWidgetsChange?.(next);
        }
        else {
            setInternalComposerWidgets(next);
        }
    }, []);
    const addComposerWidget = useCallback((widgetName, fields) => {
        const composerWidgetId = crypto.randomUUID();
        publishComposerWidgets([
            ...composerWidgetsStateRef.current.composerWidgets,
            { composerWidgetId, widgetName, fields },
        ]);
        return composerWidgetId;
    }, [publishComposerWidgets]);
    const replaceComposerWidgetFields = useCallback((composerWidgetId, fields) => {
        publishComposerWidgets(composerWidgetsStateRef.current.composerWidgets.map((widget) => widget.composerWidgetId === composerWidgetId ? { ...widget, fields } : widget));
    }, [publishComposerWidgets]);
    const removeComposerWidget = useCallback((composerWidgetId) => {
        publishComposerWidgets(composerWidgetsStateRef.current.composerWidgets.filter((widget) => widget.composerWidgetId !== composerWidgetId));
    }, [publishComposerWidgets]);
    const clearComposerWidgets = useCallback(() => {
        publishComposerWidgets([]);
    }, [publishComposerWidgets]);
    // The one way to open a canvas (all four origins). The provider supplies what only it knows — the split's viability
    // (the chat container's width, like `useCanvasViable`) and whether this host has a canvas surface — and the reducer
    // applies the opening rule. Ignored requests change nothing.
    const openCanvas = useCallback((request) => {
        dispatchCanvasEventRef.current({
            type: "canvasOpened",
            request: {
                ...request,
                viewportViable: readCanvasViable(canvasViabilityRef.current),
                hostHasCanvasSurface: hostHasCanvasSurfaceRef.current,
            },
        });
    }, []);
    // Close the open canvas; it stays re-openable as `lastClosedCanvas`. `byCustomer` = the canvas's own close control,
    // which vetoes a live re-delivery of the same identity until the next send / click / page hand-over.
    const closeCanvas = useCallback((byCustomer) => {
        dispatchCanvasEventRef.current({ type: "canvasClosed", byCustomer });
    }, []);
    const lastClosedCanvasRef = useRef(lastClosedCanvas);
    lastClosedCanvasRef.current = lastClosedCanvas;
    const reopenLastClosedCanvas = useCallback(() => {
        const last = lastClosedCanvasRef.current;
        if (last === null)
            return;
        dispatchCanvasEventRef.current({
            type: "canvasOpened",
            request: {
                canvasId: last.canvasId,
                instanceId: last.instanceId,
                anchor: last.anchor,
                origin: "widgetClick",
                justDelivered: false,
                viewportViable: readCanvasViable(canvasViabilityRef.current),
                hostHasCanvasSurface: hostHasCanvasSurfaceRef.current,
                ...(last.createdEntityId !== undefined ? { createdEntityId: last.createdEntityId } : {}),
            },
        });
    }, []);
    const setCanvasCreatedEntityId = useCallback((created) => {
        dispatchCanvasEventRef.current({ type: "canvasCreatedEntityIdSet", created });
    }, []);
    // The pointer of the OPEN canvas is remembered per conversation for a reload (`canvasStorage.ts`), for any
    // lifetime. Cleared when the canvas closes INSIDE the same conversation; a conversation switch clears the slice too,
    // but the LEFT conversation keeps its entry so coming back restores it.
    const storedCanvasConversationIdRef = useRef(undefined);
    const conversationIdForStorage = callbacks.conversationId;
    useEffect(() => {
        if (canvas !== null) {
            storeOpenCanvas(conversationIdForStorage, canvas);
            storedCanvasConversationIdRef.current = conversationIdForStorage;
            return;
        }
        if (storedCanvasConversationIdRef.current !== undefined &&
            storedCanvasConversationIdRef.current === conversationIdForStorage) {
            clearStoredCanvas(conversationIdForStorage);
        }
        storedCanvasConversationIdRef.current = undefined;
    }, [canvas, conversationIdForStorage]);
    // A conversation switch forgets the canvas and the last closed one (the LEFT conversation keeps its stored pointer).
    const resetCanvas = useCallback(() => {
        dispatchCanvasEventRef.current({ type: "canvasCleared" });
    }, []);
    // The SEND boundary: a turn canvas is over for good, a pinned one stays (`canvas-reducers.ts`).
    const resetCanvasForNewTurn = useCallback(() => {
        dispatchCanvasEventRef.current({ type: "canvasResetForNewTurn" });
    }, []);
    const sendMessageToChat = useCallback((message) => {
        return callbacksRef.current.sendMessageToChat?.(message);
    }, []);
    const prefillComposer = useCallback((text) => {
        callbacksRef.current.prefillComposer?.(text);
    }, []);
    const injectSignal = useCallback((signalText, options) => {
        return callbacksRef.current.injectSignal?.(signalText, options);
    }, []);
    const minimizeChatHost = useCallback(() => {
        callbacksRef.current.minimizeChatHost?.();
    }, []);
    const contextValue = useMemo(() => ({
        canvas,
        hostHasCanvasSurface,
        lastClosedCanvas,
        openCanvas,
        closeCanvas,
        reopenLastClosedCanvas,
        setCanvasCreatedEntityId,
        resetCanvas,
        resetCanvasForNewTurn,
        sendMessageToChat,
        prefillComposer,
        injectSignal,
        minimizeChatHost,
        chatAgentSpaceKey: callbacks.chatAgentSpaceKey,
        isConversationRunning,
        isConversationClosed,
        conversationId: callbacks.conversationId,
        composerWidgets,
        addComposerWidget,
        replaceComposerWidgetFields,
        removeComposerWidget,
        clearComposerWidgets,
        attachLibraryFilesToComposer: callbacks.attachLibraryFilesToComposer,
        onOpenConversation: callbacks.onOpenConversation,
        onSwitchConversation: callbacks.onSwitchConversation,
        isOnboardingSetupConversation: callbacks.isOnboardingSetupConversation ?? false,
        messages,
    }), [
        canvas,
        hostHasCanvasSurface,
        lastClosedCanvas,
        openCanvas,
        closeCanvas,
        reopenLastClosedCanvas,
        setCanvasCreatedEntityId,
        resetCanvas,
        resetCanvasForNewTurn,
        sendMessageToChat,
        prefillComposer,
        injectSignal,
        minimizeChatHost,
        callbacks.chatAgentSpaceKey,
        isConversationRunning,
        isConversationClosed,
        callbacks.conversationId,
        composerWidgets,
        addComposerWidget,
        replaceComposerWidgetFields,
        removeComposerWidget,
        clearComposerWidgets,
        callbacks.attachLibraryFilesToComposer,
        callbacks.onOpenConversation,
        callbacks.onSwitchConversation,
        callbacks.isOnboardingSetupConversation,
        messages,
    ]);
    return <ConversationWidgetContext.Provider value={contextValue}>{children}</ConversationWidgetContext.Provider>;
};
