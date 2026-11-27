/**
 * The test id of an inner element of a voice component: `${testId}-<part>` when the application gave the component a
 * `testId`, else the element's own fixed id (`agent-voice-…`), so an application that sets none sees the ids it had.
 */
export const agentVoicePartTestId = (testId, part, defaultTestId) => testId === undefined ? defaultTestId : `${testId}-${part}`;
