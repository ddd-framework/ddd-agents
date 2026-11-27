/** The wire name the backend writes (`ContinueInPersonalSpaceConversationWidget::WIDGET_TYPE`). */
export const CONTINUE_IN_PERSONAL_SPACE_WIDGET_TYPE = "ContinueInPersonalSpace";

/** The fields of a `ContinueInPersonalSpace` block. */
export interface ContinueInPersonalSpacePayload {
  /** The owner's request: the first message of the new conversation in the personal space. */
  request: string;
  /** The backend's reason in English; for logs and tests only — the widget shows its own translated text. */
  reason: string | null;
}

/** The payload of a block, or null when it carries no request (nothing to continue with). */
export const parseContinueInPersonalSpacePayload = (payload: string | undefined): ContinueInPersonalSpacePayload | null => {
  if (typeof payload !== "string") return null;
  let parsed: unknown;
  try {
    parsed = JSON.parse(payload);
  } catch {
    return null;
  }
  if (typeof parsed !== "object" || parsed === null) return null;
  const fields = parsed as Record<string, unknown>;
  const request = typeof fields.request === "string" ? fields.request.trim() : "";
  if (request === "") return null;
  return { request, reason: typeof fields.reason === "string" ? fields.reason : null };
};
