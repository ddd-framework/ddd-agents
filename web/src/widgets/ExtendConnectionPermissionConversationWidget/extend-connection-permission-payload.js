/** The wire name the backend writes (`ExtendConnectionPermissionConversationWidget::WIDGET_TYPE`). */
export const EXTEND_CONNECTION_PERMISSION_WIDGET_TYPE = "ExtendConnectionPermission";
/** The payload of a block, or null when it names no connection, capability or level. */
export const parseExtendConnectionPermissionPayload = (payload) => {
    if (typeof payload !== "string")
        return null;
    let parsed;
    try {
        parsed = JSON.parse(payload);
    }
    catch {
        return null;
    }
    if (typeof parsed !== "object" || parsed === null)
        return null;
    const fields = parsed;
    const connectionId = typeof fields.connectionId === "number" ? fields.connectionId : Number(fields.connectionId);
    const capability = typeof fields.capability === "string" ? fields.capability.trim() : "";
    const level = typeof fields.level === "string" ? fields.level.trim() : "";
    if (!Number.isInteger(connectionId) || connectionId <= 0 || capability === "" || level === "")
        return null;
    return {
        target: { connectionId, capability, level },
        reason: typeof fields.reason === "string" ? fields.reason : null,
    };
};
