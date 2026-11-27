/** A client id: 8 to 64 letters, digits and `-`, random per mount of the agent window (the server's pattern). */
export const createAgentUIClientId = () => {
    const randomPart = typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
    return `ui-${randomPart}`.slice(0, 64);
};
