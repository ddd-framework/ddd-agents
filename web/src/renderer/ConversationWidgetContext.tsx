import React, { ReactNode, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useAgentsEnvironment, useAgentsStateDispatch, useAgentsStateSelector } from "../provider/AgentsProvider";
import type { ConversationWidgetStatePartition } from "./conversation-widget-state-partition";
import { createAgentsChatStore } from "../chat/store/create-agents-chat-store";
import type { AgentsChatEvent } from "../chat/store/agents-chat-state";
import type { AgentsChatStore } from "../chat/store/create-agents-chat-store";
import { useAgentsChatSelector } from "../chat/store/use-agents-chat-store";
import type { CanvasState, LastClosedCanvas } from "./canvas/canvas-state";
import { CANVAS_VIABLE_MIN_WIDTH } from "../widgets/CanvasWidgetCard/use-canvas-viable";
import { ConversationComposerWidget } from "./conversation-composer-widget";
import type { CanvasCreatedEntity } from "./canvas/canvas-reducers";
import { clearStoredCanvas, storeOpenCanvas } from "./canvas/canvasStorage";
import {
  type CanvasOpenRequestFromOpener,
  type ConversationWidgetCallbacks,
  ConversationWidgetContext,
  defaultConversationWidgetCallbacks,
  type InjectSignalOptions,
} from "./ConversationWidgetCallbacksContext";

// The callbacks, their context and the chat-free hosts live in `ConversationWidgetCallbacksContext.tsx`; this module
// adds the provider that reads the chat's state (the package chat store or an app's partition) and re-exports the rest.
export * from "./ConversationWidgetCallbacksContext";

// Number of mounted agentic chat surfaces (widget, full-page chat, dashboard). Drives the app's "a chat surface is
// open" action (`buildChatSurfaceOpenChangedAction`, e.g. a flag of the app's own flows): true while ≥1 is open.
// Module-level so multiple providers share one count.
let agenticChatMountCount = 0;

export interface ConversationWidgetProviderProps {
  children: ReactNode;
  callbacks: Partial<ConversationWidgetCallbacks>;
  /**
   * The chat slice partition this provider's canvas lives in. The shell is shared by the two chat doors, so the door
   * says which partition: `AgentChat` passes `AgentChatInstance`; the AI-Extensions chat, every story and every spec
   * keep the default. A prop, not a callback — `callbacks` is `Partial`, and the partition is not optional.
   */
  sliceInstance?: ConversationWidgetStatePartition;
  /**
   * The package chat store of the chat: the agent chat passes its window's store and the provider reads
   * and writes the canvas there. Without it the provider reads a partition of the app's own chat state
   * (`sliceInstance`, else the environment's `defaultChatStatePartition`).
   */
  chatStore?: AgentsChatStore;
  /**
   * False for a host that renders the chat without the split (`ConversationView.disableSplitView`): there is nowhere
   * to open a canvas, so every open request is ignored with reason `noSurface`. Default true.
   */
  hostHasCanvasSurface?: boolean;
}

/** The canvas transitions the provider dispatches — the canvas events of the package chat store. */
type ConversationWidgetCanvasEvent = Extract<
  AgentsChatEvent,
  { type: "canvasOpened" | "canvasClosed" | "canvasResetForNewTurn" | "canvasCleared" | "canvasCreatedEntityIdSet" }
>;

/** What the provider reads from the chat's state and how it writes a canvas transition back. */
interface ConversationWidgetChatState {
  canvas: CanvasState | null;
  lastClosedCanvas: LastClosedCanvas | null;
  isConversationRunning: boolean;
  runState: string | null | undefined;
  messages: ConversationWidgetCallbacks["messages"];
  dispatchCanvasEvent: (event: ConversationWidgetCanvasEvent) => void;
}

type ConversationWidgetProviderBodyProps = Omit<ConversationWidgetProviderProps, "sliceInstance" | "chatStore"> & {
  chatState: ConversationWidgetChatState;
};

const selectCanvas = (state: { canvas: CanvasState | null }) => state.canvas;
const selectLastClosedCanvas = (state: { lastClosedCanvas: LastClosedCanvas | null }) => state.lastClosedCanvas;
const selectAiThinking = (state: { aiThinking: boolean }) => state.aiThinking;
const selectRunState = (state: { conversation: { runState?: string | null } | null }) => state.conversation?.runState;
const selectMessages = (state: { messages: ConversationWidgetCallbacks["messages"] }) => state.messages;

/** The provider over the package chat store of the chat. */
const ChatStoreConversationWidgetProvider = (
  props: Omit<ConversationWidgetProviderProps, "sliceInstance" | "chatStore"> & { chatStore: AgentsChatStore },
) => {
  const { chatStore, ...bodyProps } = props;
  const canvas = useAgentsChatSelector(chatStore, selectCanvas);
  const lastClosedCanvas = useAgentsChatSelector(chatStore, selectLastClosedCanvas);
  const isConversationRunning = useAgentsChatSelector(chatStore, selectAiThinking);
  const runState = useAgentsChatSelector(chatStore, selectRunState);
  const messages = useAgentsChatSelector(chatStore, selectMessages);
  const chatState = useMemo<ConversationWidgetChatState>(
    () => ({
      canvas,
      lastClosedCanvas,
      isConversationRunning,
      runState,
      messages,
      dispatchCanvasEvent: chatStore.dispatch,
    }),
    [canvas, lastClosedCanvas, isConversationRunning, runState, messages, chatStore],
  );
  return <ConversationWidgetProviderBody {...bodyProps} chatState={chatState} />;
};

/** The provider over a partition of the older chat state (the AI-Extensions chat, stories, specs). */
const SliceConversationWidgetProvider = (
  props: Omit<ConversationWidgetProviderProps, "sliceInstance" | "chatStore"> & {
    sliceInstance: ConversationWidgetStatePartition;
  },
) => {
  const { sliceInstance, ...bodyProps } = props;
  const dispatch = useAgentsStateDispatch();
  // One selector per field, not one returning an object, so an unrelated store change does not re-render every widget
  // through a fresh object identity.
  const canvas = useAgentsStateSelector((state) => sliceInstance.selectState(state).canvas);
  const lastClosedCanvas = useAgentsStateSelector((state) => sliceInstance.selectState(state).lastClosedCanvas);
  const isConversationRunning = useAgentsStateSelector((state) => sliceInstance.selectState(state).aiThinking);
  const runState = useAgentsStateSelector((state) => sliceInstance.selectState(state).conversation?.runState);
  const messages = useAgentsStateSelector((state) => sliceInstance.selectState(state).messages);
  const dispatchCanvasEvent = useCallback(
    (event: ConversationWidgetCanvasEvent) => {
      const { actions } = sliceInstance;
      if (event.type === "canvasOpened") dispatch(actions.openCanvas(event.request));
      else if (event.type === "canvasClosed") dispatch(actions.closeCanvas({ byCustomer: event.byCustomer }));
      else if (event.type === "canvasResetForNewTurn") dispatch(actions.resetCanvasForNewTurn());
      else if (event.type === "canvasCleared") dispatch(actions.clearCanvas());
      else dispatch(actions.setCanvasCreatedEntityId(event.created));
    },
    [dispatch, sliceInstance],
  );
  const chatState = useMemo<ConversationWidgetChatState>(
    () => ({ canvas, lastClosedCanvas, isConversationRunning, runState, messages, dispatchCanvasEvent }),
    [canvas, lastClosedCanvas, isConversationRunning, runState, messages, dispatchCanvasEvent],
  );
  return <ConversationWidgetProviderBody {...bodyProps} chatState={chatState} />;
};

/** The provider over a chat store of its own — a host that brings neither a chat store nor a partition (a story). */
const OwnChatStoreConversationWidgetProvider = (
  props: Omit<ConversationWidgetProviderProps, "sliceInstance" | "chatStore">,
) => {
  const [ownChatStore] = useState(createAgentsChatStore);
  return <ChatStoreConversationWidgetProvider {...props} chatStore={ownChatStore} />;
};

/**
 * The widget callbacks of one chat, the canvas model included. Reads the chat's state from the package chat store when
 * the host passes one (`chatStore`), else from a partition of the app's own chat state (`sliceInstance`, or the
 * environment's `defaultChatStatePartition` — host application: the AI-Extensions chat), else from a store of its own.
 */
export const ConversationWidgetProvider = (props: ConversationWidgetProviderProps) => {
  const { sliceInstance, chatStore, ...bodyProps } = props;
  const { defaultChatStatePartition } = useAgentsEnvironment();
  const statePartition = sliceInstance ?? defaultChatStatePartition;
  if (chatStore) return <ChatStoreConversationWidgetProvider {...bodyProps} chatStore={chatStore} />;
  if (statePartition) return <SliceConversationWidgetProvider {...bodyProps} sliceInstance={statePartition} />;
  return <OwnChatStoreConversationWidgetProvider {...bodyProps} />;
};

const ConversationWidgetProviderBody = ({
  children,
  callbacks,
  hostHasCanvasSurface = true,
  chatState,
}: ConversationWidgetProviderBodyProps) => {
  const dispatch = useAgentsStateDispatch();
  const { buildChatSurfaceOpenChangedAction } = useAgentsEnvironment();
  const { canvas, lastClosedCanvas, isConversationRunning, runState, messages } = chatState;
  const isConversationClosed = runState === "COMPLETED" || runState === "CANCELLED";
  const dispatchCanvasEventRef = useRef(chatState.dispatchCanvasEvent);
  dispatchCanvasEventRef.current = chatState.dispatchCanvasEvent;
  const hostHasCanvasSurfaceRef = useRef(hostHasCanvasSurface);
  hostHasCanvasSurfaceRef.current = hostHasCanvasSurface;

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
  const [internalComposerWidgets, setInternalComposerWidgets] = useState<ConversationComposerWidget[]>([]);
  const composerWidgets = isComposerWidgetsControlled
    ? (callbacks.composerWidgets as ConversationComposerWidget[])
    : internalComposerWidgets;

  // Mirrors the latest resolved composerWidgets + controlled flag so the mutator callbacks below can compute the
  // next array off REAL current state while keeping stable identities across renders ([] deps) — same trick as
  // `callbacksRef` above.
  const composerWidgetsStateRef = useRef({ composerWidgets, isComposerWidgetsControlled });
  composerWidgetsStateRef.current = { composerWidgets, isComposerWidgetsControlled };

  const publishComposerWidgets = useCallback((next: ConversationComposerWidget[]) => {
    if (composerWidgetsStateRef.current.isComposerWidgetsControlled) {
      callbacksRef.current.onComposerWidgetsChange?.(next);
    } else {
      setInternalComposerWidgets(next);
    }
  }, []);

  const addComposerWidget = useCallback(
    (widgetName: string, fields: Record<string, unknown>): string => {
      const composerWidgetId = crypto.randomUUID();
      publishComposerWidgets([
        ...composerWidgetsStateRef.current.composerWidgets,
        { composerWidgetId, widgetName, fields },
      ]);
      return composerWidgetId;
    },
    [publishComposerWidgets],
  );

  const replaceComposerWidgetFields = useCallback(
    (composerWidgetId: string, fields: Record<string, unknown>) => {
      publishComposerWidgets(
        composerWidgetsStateRef.current.composerWidgets.map((widget) =>
          widget.composerWidgetId === composerWidgetId ? { ...widget, fields } : widget,
        ),
      );
    },
    [publishComposerWidgets],
  );

  const removeComposerWidget = useCallback(
    (composerWidgetId: string) => {
      publishComposerWidgets(
        composerWidgetsStateRef.current.composerWidgets.filter(
          (widget) => widget.composerWidgetId !== composerWidgetId,
        ),
      );
    },
    [publishComposerWidgets],
  );

  const clearComposerWidgets = useCallback(() => {
    publishComposerWidgets([]);
  }, [publishComposerWidgets]);

  // The one way to open a canvas (all four origins). The provider supplies what only it knows — the split's viability
  // (measured on the window, like `useCanvasViable`) and whether this host has a canvas surface — and the reducer
  // applies the opening rule. Ignored requests change nothing.
  const openCanvas = useCallback(
    (request: CanvasOpenRequestFromOpener) => {
      dispatchCanvasEventRef.current({
        type: "canvasOpened",
        request: {
          ...request,
          viewportViable: window.innerWidth >= CANVAS_VIABLE_MIN_WIDTH,
          hostHasCanvasSurface: hostHasCanvasSurfaceRef.current,
        },
      });
    },
    [],
  );

  // Close the open canvas; it stays re-openable as `lastClosedCanvas`. `byCustomer` = the canvas's own close control,
  // which vetoes a live re-delivery of the same identity until the next send / click / page hand-over.
  const closeCanvas = useCallback(
    (byCustomer: boolean) => {
      dispatchCanvasEventRef.current({ type: "canvasClosed", byCustomer });
    },
    [],
  );

  const lastClosedCanvasRef = useRef(lastClosedCanvas);
  lastClosedCanvasRef.current = lastClosedCanvas;
  const reopenLastClosedCanvas = useCallback(() => {
    const last = lastClosedCanvasRef.current;
    if (last === null) return;
    dispatchCanvasEventRef.current({
      type: "canvasOpened",
      request: {
        canvasId: last.canvasId,
        instanceId: last.instanceId,
        anchor: last.anchor,
        origin: "cardClick",
        justDelivered: false,
        viewportViable: window.innerWidth >= CANVAS_VIABLE_MIN_WIDTH,
        hostHasCanvasSurface: hostHasCanvasSurfaceRef.current,
        ...(last.createdEntityId !== undefined ? { createdEntityId: last.createdEntityId } : {}),
      },
    });
  }, []);

  const setCanvasCreatedEntityId = useCallback(
    (created: CanvasCreatedEntity) => {
      dispatchCanvasEventRef.current({ type: "canvasCreatedEntityIdSet", created });
    },
    [],
  );

  // The pointer of the OPEN canvas is remembered per conversation for a reload (`canvasStorage.ts`), for any
  // lifetime. Cleared when the canvas closes INSIDE the same conversation; a conversation switch clears the slice too,
  // but the LEFT conversation keeps its entry so coming back restores it.
  const storedCanvasConversationIdRef = useRef<number | undefined>(undefined);
  const conversationIdForStorage = callbacks.conversationId;
  useEffect(() => {
    if (canvas !== null) {
      storeOpenCanvas(conversationIdForStorage, canvas);
      storedCanvasConversationIdRef.current = conversationIdForStorage;
      return;
    }
    if (
      storedCanvasConversationIdRef.current !== undefined &&
      storedCanvasConversationIdRef.current === conversationIdForStorage
    ) {
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

  const sendMessageToChat = useCallback((message: string) => {
    return callbacksRef.current.sendMessageToChat?.(message);
  }, []);

  const prefillComposer = useCallback((text: string) => {
    callbacksRef.current.prefillComposer?.(text);
  }, []);

  const injectSignal = useCallback((signalText: string, options?: InjectSignalOptions) => {
    return callbacksRef.current.injectSignal?.(signalText, options);
  }, []);

  const minimizeChatHost = useCallback(() => {
    callbacksRef.current.minimizeChatHost?.();
  }, []);

  const contextValue = useMemo<ConversationWidgetCallbacks>(
    () => ({
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
      onOpenConversation: callbacks.onOpenConversation,
      onSwitchConversation: callbacks.onSwitchConversation,
      isOnboardingSetupConversation: callbacks.isOnboardingSetupConversation ?? false,
      messages,
    }),
    [
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
      callbacks.onOpenConversation,
      callbacks.onSwitchConversation,
      callbacks.isOnboardingSetupConversation,
      messages,
    ],
  );

  return <ConversationWidgetContext.Provider value={contextValue}>{children}</ConversationWidgetContext.Provider>;
};

