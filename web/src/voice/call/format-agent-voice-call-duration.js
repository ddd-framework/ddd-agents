/** `m:ss` for a call shorter than an hour (the backend caps a call's duration). */
export const formatAgentVoiceCallDuration = (elapsedSeconds) => {
    const minutes = Math.floor(elapsedSeconds / 60);
    const seconds = elapsedSeconds % 60;
    return `${minutes}:${String(seconds).padStart(2, "0")}`;
};
