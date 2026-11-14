import { createContext, ReactNode, useContext, useMemo } from "react";
import { ConversationComposerWidget } from "./conversation-composer-widget";
import { CanvasOpenRequest, CanvasState, LastClosedCanvas } from "./canvas/canvas-state";
import type { CanvasCreatedEntity } from "./canvas/canvas-reducers";

// The widget callbacks without the chat: their type, the no-op defaults, the context, the two hosts that need no chat
// state and the hook every widget reads. `ConversationWidgetContext.tsx` adds the provider bound to the chat's state
// partition and re-exports this module, so package code that renders widgets never loads the chat.

/** A message of the transcript a widget reads (its id and author role); the chat's messages carry more. */
export interface ConversationWidgetTranscriptMessage {
  id?: number | string | null;
  role?: string | null;
}

export type ChatEngagementMediaItem = {
  id?: number;
  publicUrl: string;
  publicThumbnailUrl?: string;
  type?: string;
};

export type ChatEngagementMediaItems = ChatEngagementMediaItem[];

/** A post or event the owner edits from the chat — the fields the edit widget reads off its payload. */
export type ChatEngagementTarget =
  | {
      kind: "post";
      id: number;
      title?: string;
      content?: string;
      status?: string;
      scheduleAt?: string;
      startDate?: string;
      endDate?: string;
      mediaItems?: ChatEngagementMediaItems;
    }
  | {
      kind: "event";
      id: number;
      title?: string;
      content?: string;
      status?: string;
      scheduleAt?: string;
      startDate?: string;
      endDate?: string;
      mediaItems?: ChatEngagementMediaItems;
    };

export interface OpenEngagementEditConversationWidgetPayload {
  target: ChatEngagementTarget;
  mode: "edit";
}

/**
 * What an opener passes to `openCanvas`: the request minus the two facts the PROVIDER knows better than any widget —
 * whether the viewport is wide enough for the split, and whether the host renders a canvas surface at all.
 */
export type CanvasOpenRequestFromOpener = Omit<CanvasOpenRequest, "viewportViable" | "hostHasCanvasSurface">;

export type InjectSignalOptions = {
  /**
   * The signal is context for the CURRENT turn (e.g. specialist instructions riding along a just-posted user
   * message) and must NOT drive its own agent turn / produce its own reply. The chat host delivers it only while a
   * turn is observed running — a signal appended mid-turn lands in the conversation history without re-driving the
   * agent (the backend only re-drives when the LAST message is an unanswered inbound). If no turn starts, the signal
   * is dropped.
   */
  withoutOwnAgentTurn?: boolean;
  /**
   * Overrides the server-side default signal source (FRONTEND_WIDGET_CLICK), e.g. the business-basics form
   * submit's own source.
   */
  signalSource?: string;
  /**
   * Who produced the signal. CUSTOMER_CLICK (the default when omitted): the customer pressed a widget button or CTA,
   * so the chat scrolls to follow the turn and a refusal (closed conversation, backend 400) goes on the error card.
   * PROGRAMMATIC: an effect or ride-along emitted it without a customer action (store-diff feedback, the
   * widget-invalid corrective, specialist instructions) — refusals are dropped silently, nothing scrolls.
   */
  signalOrigin?: "CUSTOMER_CLICK" | "PROGRAMMATIC";
};

export interface ConversationWidgetCallbacks {
  /**
   * THE CANVAS MODEL. The canvas is data in the chat's slice
   * partition: a pointer to its anchor message (or page), an identity and a render generation — see
   * `canvas/canvas-state.ts`. `openCanvas` is the one way to open for all four origins; the opening rule
   * (`canvas/decide-canvas-open.ts`) decides inside the reducer. `closeCanvas(byCustomer)` keeps the canvas re-openable
   * as `lastClosedCanvas`. The shell (`CanvasPane`) renders the body from `canvasBodies`; nothing else
   * holds a canvas.
   */
  canvas: CanvasState | null;
  /**
   * Whether this host renders a canvas surface at all. A mount-time opener reads it to tell "the customer has no
   * canvas here" from "my request was dropped while the host was still coming up" — the latter is worth asking again.
   */
  hostHasCanvasSurface: boolean;
  lastClosedCanvas: LastClosedCanvas | null;
  openCanvas: (request: CanvasOpenRequestFromOpener) => void;
  closeCanvas: (byCustomer: boolean) => void;
  /**
   * Re-open `lastClosedCanvas` as a customer action (origin `cardClick`): the canvas toggle button on the divider, the
   * narrow-viewport re-open control, the compact → expand path. No-op when nothing was closed.
   */
  reopenLastClosedCanvas: () => void;
  /**
   * A body created its entity (the post editor's draft): remember the id on the canvas it was created for, so a
   * re-open or a restore edits that entity instead of creating another. Identity + generation checked in the reducer.
   */
  setCanvasCreatedEntityId: (created: CanvasCreatedEntity) => void;
  /**
   * Contracts (minimizes) the host drawer/modal so the app behind stays
   * visible during navigation. Called by the Navigate tool before animating.
   * No-op by default; hosts that support compacting override this.
   */
  minimizeChatHost: () => void;
  /**
   * Forget the canvas and the last closed one — a conversation switch, where the previous conversation's canvas is
   * stale outright (`clearCanvas` in the canvas model; the LEFT conversation keeps its stored pointer).
   */
  resetCanvas: () => void;
  /**
   * The SEND boundary of the canvas model, called by the shell on a typed send (the hosts call it for widget sends
   * too): a `turn` canvas is over for good, a `pinned` canvas stays open — see `canvas-reducers.ts`.
   */
  resetCanvasForNewTurn: () => void;
  /**
   * Post the message as a visible USER bubble and drive one agent turn — the widget-button twin of the typed input.
   * Mirrors `injectSignal`'s contract: a host may return a promise resolving to `true` only once the send was
   * actually accepted (dispatched/posted), `false` when a caller-side guard dropped it (a closed conversation, an
   * in-flight start). `void`/no result counts as accepted for a host that doesn't report (e.g. Conversation.tsx) —
   * only a widget that explicitly awaits `false` must keep its controls usable.
   */
  sendMessageToChat: (message: string) => void | Promise<boolean>;
  /**
   * Put text into the chat's composer (the message input) and focus it, WITHOUT sending — the widget-button twin
   * of a host-owned control using `ConversationMessageInputHandle.insertTextIntoInput` directly (e.g. the agent "+"
   * menu's "Generate image" entry). Used by a widget option that is a TEMPLATE with a blank the owner must still
   * fill in (QuickReplies' placeholder-option pill): sending it as-is would post the literal blank as the owner's
   * message. No-op by default; hosts that own a chat input wire it to that input's imperative handle.
   */
  prefillComposer: (text: string) => void;
  /**
   * Inject a model-visible / user-invisible SIGNAL into the CURRENT conversation and drive one agent turn. Unlike
   * `sendMessageToChat` (which posts a visible USER message), this does NOT render a chat bubble — it tells the agent
   * something happened (e.g. "User selected image A") and lets it continue. The reply lands via the existing realtime
   * signal, exactly like a posted message. Set by chat hosts that own a conversation id; no-op by default.
   * Hosts may return a promise resolving to true only after the signal was accepted. False or no result
   * means delivery is not confirmed; callers that retire controls must wait for true.
   */
  injectSignal: (signalText: string, options?: InjectSignalOptions) => void | Promise<boolean>;
  /**
   * Key of the agent space the chat is scoped to. Set by hosts that mount the chat outside a
   * route of the agent space (e.g. a host whose panel opens on a page with no agent space
   * in the URL). Widget hydrators fall back to this when the URL path / active-agent-space
   * store can't supply one — without it they fetch with a missing key and every section 404s.
   * Undefined for in-route hosts, which keep their existing behavior.
   */
  chatAgentSpaceKey?: number;
  /**
   * Whether the chat's agent turn is RUNNING right now (the slice partition's `aiThinking`). A status widget whose
   * backing run is still open reads this to tell "being applied" from "paused — waiting for the owner's reply": the
   * turn ended (a question, a step budget notice) while the run stays open. Read from the provider's slice, never
   * passed by a host.
   */
  isConversationRunning: boolean;
  /**
   * The conversation's run is over (`runState` COMPLETED or CANCELLED): the backend rejects further input. Read from
   * the provider's slice, for a canvas body, which gets no per-message `interactive` flag.
   */
  isConversationClosed: boolean;
  /**
   * Conversation id the chat is currently showing. Set by hosts that know it (AgentChat). Scopes the stored canvas
   * pointer (`canvas/canvasStorage.ts`) — undefined on hosts that don't set it simply means the canvas does not come
   * back after a reload there.
   */
  conversationId?: number;
  /**
   * Composer widgets currently held in the chat input — the composer-side twin of a rendered widget (a widget
   * instance that lives in the input before send; see `ConversationComposerWidget`). Rendered by the generic
   * `ConversationMessageInputComposerWidgets` strip via each entry's registry `composerComponent`.
   *
   * Always a concrete array (never undefined on the context value itself — only the CALLBACKS prop a host supplies
   * is optional, deciding ownership):
   *  - **Controlled** — a host passes `composerWidgets` (e.g. an app's builder page, whose preview
   *    is a *sibling* of `<Conversation>`). Every mutation below is then routed to the host's
   *    `onComposerWidgetsChange(next)` instead of local state.
   *  - **Uncontrolled** — the host omits it (every chat-as-host surface today). The provider owns a
   *    `useState<ConversationComposerWidget[]>([])` and a *descendant* (e.g. an app's editor drawer) mutates it
   *    directly via the functions below.
   */
  composerWidgets: ConversationComposerWidget[];
  /** Add a new composer widget (or the first element of one); returns its client-only `composerWidgetId`. */
  addComposerWidget: (widgetName: string, fields: Record<string, unknown>) => string;
  /** Replace one composer widget's fields in place (e.g. drop one element out of several). */
  replaceComposerWidgetFields: (composerWidgetId: string, fields: Record<string, unknown>) => void;
  /** Remove one composer widget entirely (its ✕, or Backspace when it has no removable parts left). */
  removeComposerWidget: (composerWidgetId: string) => void;
  /** Remove every composer widget — called on send. */
  clearComposerWidgets: () => void;
  /**
   * Controlled-mode sink: called with the FULL next array on every composer-widget mutation
   * (add/replace/remove/clear). Ignored (mutations apply to local state instead) when the host doesn't also pass
   * `composerWidgets`.
   */
  onComposerWidgetsChange?: (next: ConversationComposerWidget[]) => void;
  /* Jump to a past conversation (and, when a hit is clicked, scroll to that message by id). Set by chat hosts that can
   * switch the active conversation (a host) — used by the ConversationSearchResults widget's open-arrow. Undefined →
   * the arrow is a no-op (e.g. hosts with no conversation switcher).
   */
  onOpenConversation?: (conversationId: number, messageId?: number) => void;
  /**
   * Switch THIS chat to another conversation of the same agent space in place (no new tab) — the door a widget that links
   * to related work ("Open the conversation") takes when that conversation belongs to the agent space the chat is open
   * on. Undefined on hosts without a conversation switcher.
   */
  onSwitchConversation?: (conversationId: number) => void;
  /**
   * True while the chat is the guided first-run setup: the host mounts the agent on ONE conversation it may not
   * leave (the full-page setup route) AND that conversation's purpose declares a customer progress stepper — the
   * same presence contract the stepper itself renders on (no purpose-name checks in the frontend). Widgets read it
   * to offer setup-only affordances, e.g. a connect widget's "Skip for now" deferral. False on every
   * other surface (the floating widget, the website builder, a purposed setup conversation reopened from history).
   */
  isOnboardingSetupConversation?: boolean;
  /**
   * The transcript this widget lives in, read from the door's own partition (`sliceInstance.selectState`) — the same
   * source `canvas` above reads. `null` before the conversation loads. A one-shot widget (e.g. QuickReplies) reads
   * this to tell "the owner already answered a later question" from its own `messageId`: any message with
   * `role === "USER"` AFTER the widget's host message means the widget is stale, regardless of `interactive` (which
   * stays true for the whole live session, not just until the next owner message).
   */
  messages: ConversationWidgetTranscriptMessage[] | null;
}

export const defaultConversationWidgetCallbacks: ConversationWidgetCallbacks = {
  canvas: null,
  hostHasCanvasSurface: false,
  lastClosedCanvas: null,
  openCanvas: () => {},
  closeCanvas: () => {},
  reopenLastClosedCanvas: () => {},
  setCanvasCreatedEntityId: () => {},
  resetCanvas: () => {},
  resetCanvasForNewTurn: () => {},
  sendMessageToChat: () => {},
  prefillComposer: () => {},
  injectSignal: () => {},
  minimizeChatHost: () => {},
  chatAgentSpaceKey: undefined,
  isConversationRunning: false,
  isConversationClosed: false,
  conversationId: undefined,
  composerWidgets: [],
  addComposerWidget: () => "",
  replaceComposerWidgetFields: () => {},
  removeComposerWidget: () => {},
  clearComposerWidgets: () => {},
  isOnboardingSetupConversation: false,
  messages: null,
};

/** The widget callbacks a chat host (or a standalone host) supplies to every widget below it. */
export const ConversationWidgetContext = createContext<ConversationWidgetCallbacks>(defaultConversationWidgetCallbacks);

/** Static capture host: no conversation, persistence, canvas effects or action callbacks. */
export const ReadOnlyConversationWidgetProvider = (props: { children: ReactNode; agentSpaceKey: number }) => {
  const { children, agentSpaceKey } = props;
  return (
    <ConversationWidgetContext.Provider value={{ ...defaultConversationWidgetCallbacks, chatAgentSpaceKey: agentSpaceKey }}>
      {children}
    </ConversationWidgetContext.Provider>
  );
};

/**
 * A host outside the chat that supplies its OWN callbacks — the widget preview keeps its canvas and its
 * composer log itself. Every callback it leaves out keeps the no-op default; no conversation slice is involved.
 */
export const StandaloneConversationWidgetProvider = (props: {
  children: ReactNode;
  callbacks: Partial<ConversationWidgetCallbacks>;
}) => {
  const { children, callbacks } = props;
  const value = useMemo(() => ({ ...defaultConversationWidgetCallbacks, ...callbacks }), [callbacks]);
  return <ConversationWidgetContext.Provider value={value}>{children}</ConversationWidgetContext.Provider>;
};

export const useConversationWidgetContext = () => useContext(ConversationWidgetContext);
