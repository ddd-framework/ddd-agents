/**
 * The named assistant a chat can show (the assistant identity the host hands in) — the host application's older
 * AI-Extensions chat passes a prompt extension here; the agent chat passes none. Only the fields the view reads.
 */
export interface ChatAssistantIdentity {
  id?: number | null;
  name?: string | null;
  description?: string | null;
  /** The name of the assistant's icon in the app's icon set. */
  iconClass?: string | null;
}

/** The group the named assistant belongs to; its alias picks the chat's colour theme. */
export interface ChatAssistantCategory {
  alias?: string | null;
  name?: string | null;
}

/** An opening the named assistant suggests on its intro card. */
export interface ChatAssistantOpening {
  name?: string | null;
  description?: string | null;
  iconClass?: string | null;
  /** The text the opening sends when picked. */
  openingText?: string | null;
}
