/**
 * An `AgentWindowAdapter` over the application's own window state: `stepAside()` remembers the mode and shows the
 * aside mode, `restore()` goes back to the remembered mode, `setMode()` shows a known mode. Stepping aside before the
 * agent opens a screen, marks elements, acts on the screen or takes a picture is the default.
 */
export const createAgentWindowAdapter = (options) => {
    const asideMode = options.asideMode ?? 'aside';
    const stepAsideFor = options.stepAsideFor ?? ['OPEN_TARGET', 'HIGHLIGHT', 'READ_SCREEN', 'ACT_ON_SCREEN'];
    let modeBeforeAside = null;
    return {
        stepAside: async () => {
            const currentMode = options.getMode();
            if (currentMode === asideMode)
                return;
            modeBeforeAside = currentMode;
            await options.applyMode(asideMode);
        },
        restore: async () => {
            if (modeBeforeAside === null)
                return;
            const mode = modeBeforeAside;
            modeBeforeAside = null;
            await options.applyMode(mode);
        },
        setMode: async (mode) => {
            if (!options.modes.includes(mode))
                return false;
            modeBeforeAside = null;
            await options.applyMode(mode);
            return true;
        },
        shouldStepAsideBefore: (requestType) => stepAsideFor.includes(requestType),
        getMode: () => options.getMode(),
    };
};
