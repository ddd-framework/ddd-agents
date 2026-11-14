import type { AgentsStateAction } from "../provider/environment";
import type { CanvasCreatedEntity } from "./canvas/canvas-reducers";
import type { CanvasOpenRequest, CanvasState, LastClosedCanvas } from "./canvas/canvas-state";
import type { ConversationWidgetTranscriptMessage } from "./ConversationWidgetCallbacksContext";

/** What the widget provider reads from a partition of an app's own chat state. */
export interface ConversationWidgetPartitionState {
  canvas: CanvasState | null;
  lastClosedCanvas: LastClosedCanvas | null;
  aiThinking: boolean;
  conversation: { runState?: string | null } | null;
  messages: ConversationWidgetTranscriptMessage[] | null;
}

/**
 * A partition of an app's own chat state in its state store — an app's older chat that is not on the package chat
 * store yet (host application: the AI-Extensions chat's slice partition). The widget provider reads it through the
 * environment's `stateStore` and dispatches the canvas transitions as the partition's actions.
 */
export interface ConversationWidgetStatePartition {
  actions: {
    openCanvas: (request: CanvasOpenRequest) => AgentsStateAction;
    closeCanvas: (payload: { byCustomer: boolean }) => AgentsStateAction;
    resetCanvasForNewTurn: () => AgentsStateAction;
    clearCanvas: () => AgentsStateAction;
    setCanvasCreatedEntityId: (created: CanvasCreatedEntity) => AgentsStateAction;
  };
  selectState: (state: any) => ConversationWidgetPartitionState;
}
