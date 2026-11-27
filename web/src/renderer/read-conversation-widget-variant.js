/**
 * Read just the `variant` flag out of a raw widget payload string, even when the
 * rest of the payload is incomplete (so the agent can request a density with a
 * minimal `{"variant":"compact"}` and let the data hydrate).
 */
export const readPayloadVariant = (rawPayload) => {
    try {
        const parsed = JSON.parse(rawPayload);
        if (parsed && (parsed.variant === "compact" || parsed.variant === "full")) {
            return parsed.variant;
        }
    }
    catch {
        // Non-JSON or empty payload — no variant to read.
    }
    return undefined;
};
/**
 * Overlay the agent's requested `variant` onto the resolved widget data. The agent's
 * payload variant always wins; when the payload omits it the resolved data's own
 * variant (or the widget default) stands.
 */
export const withConversationWidgetVariant = (base, rawPayload) => {
    if (!base) {
        return base;
    }
    const variant = readPayloadVariant(rawPayload);
    return variant ? { ...base, variant } : base;
};
