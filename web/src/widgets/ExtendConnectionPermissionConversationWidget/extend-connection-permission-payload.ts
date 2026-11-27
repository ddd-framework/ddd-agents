import type { AgentsConnectionPermissionTarget } from "../../provider/environment";

/** The wire name the backend writes (`ExtendConnectionPermissionConversationWidget::WIDGET_TYPE`). */
export const EXTEND_CONNECTION_PERMISSION_WIDGET_TYPE = "ExtendConnectionPermission";

/** The fields of an `ExtendConnectionPermission` block. */
export interface ExtendConnectionPermissionPayload {
  /** The connection, capability and level the step needs. */
  target: AgentsConnectionPermissionTarget;
  /** The backend's reason in English; for logs and tests only — the widget shows its own translated text. */
  reason: string | null;
}

/** The payload of a block, or null when it names no connection, capability or level. */
export const parseExtendConnectionPermissionPayload = (payload: string | undefined): ExtendConnectionPermissionPayload | null => {
  if (typeof payload !== "string") return null;
  let parsed: unknown;
  try {
    parsed = JSON.parse(payload);
  } catch {
    return null;
  }
  if (typeof parsed !== "object" || parsed === null) return null;
  const fields = parsed as Record<string, unknown>;
  const connectionId = typeof fields.connectionId === "number" ? fields.connectionId : Number(fields.connectionId);
  const capability = typeof fields.capability === "string" ? fields.capability.trim() : "";
  const level = typeof fields.level === "string" ? fields.level.trim() : "";
  if (!Number.isInteger(connectionId) || connectionId <= 0 || capability === "" || level === "") return null;
  return {
    target: { connectionId, capability, level },
    reason: typeof fields.reason === "string" ? fields.reason : null,
  };
};
