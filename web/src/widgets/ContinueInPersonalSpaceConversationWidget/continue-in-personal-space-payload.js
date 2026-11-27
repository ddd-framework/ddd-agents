/** The wire name the backend writes (`ContinueInPersonalSpaceConversationWidget::WIDGET_TYPE`). */
export const CONTINUE_IN_PERSONAL_SPACE_WIDGET_TYPE = "ContinueInPersonalSpace";
/** The payload of a block, or null when it carries no request (nothing to continue with). */
export const parseContinueInPersonalSpacePayload = (payload) => {
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
    const request = typeof fields.request === "string" ? fields.request.trim() : "";
    if (request === "")
        return null;
    return { request, reason: typeof fields.reason === "string" ? fields.reason : null };
};
