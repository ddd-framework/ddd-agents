import type { CanvasState } from "./canvas-state";

/**
 * What every canvas BODY receives from the shell. The body derives everything else itself: a message-anchored body
 * parses its widget's fence out of the anchor message (the same parser the inline widget uses), a page-anchored body
 * hydrates from the agent space. Nothing is handed over that the transcript already holds.
 *
 * A type-only module: bodies import it without touching `canvasBodies.tsx`, which stays imported by the shell alone.
 */
export interface CanvasBodyProps {
  canvas: CanvasState;
  /**
   * The key of the agent space the chat is scoped to (`chatAgentSpaceKey`), for a body whose anchor is a message and
   * whose fence names no other agent space. A page anchor carries its own agent space.
   */
  agentSpaceKey?: number;
  /** Normalised markdown of the anchor message, for a body that parses its fence out of it. Null for a page anchor. */
  anchorMessageMarkdown: string | null;
}
