/** The `MultiChoice` fence payload: an object with an `options` list; anything else renders nothing. */
export const parseMultiChoicePayload = (raw) => {
    try {
        const parsed = JSON.parse(raw);
        if (!parsed || !Array.isArray(parsed.options))
            return null;
        return parsed;
    }
    catch {
        return null;
    }
};
