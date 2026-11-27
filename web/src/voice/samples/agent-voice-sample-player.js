// The player of the voice samples of a voice picker: ONE audio element per page, so a sample that starts stops the one
// playing. The application hands in how a sample is fetched (`fetchSample`): the WAV comes only through its backend
// (`AdminAgentVoiceSamplesTrait`, the admin session), never from the object store. Each sample is fetched once per page
// and kept as an object URL. Framework-free: the React hook of `AgentVoiceSampleButton` subscribes to the state here.
/** The key of a sample: voice and language. */
export const agentVoiceSampleKeyOf = (voiceId, languageCode) => `${voiceId}/${languageCode}`;
let sharedAudioElement = null;
const objectUrlBySampleKey = new Map();
let playerState = { playingSampleKey: null, loadingSampleKey: null };
const playerStateListeners = new Set();
const setPlayerState = (change) => {
    playerState = { ...playerState, ...change };
    for (const listener of playerStateListeners)
        listener();
};
const audioElementOf = () => {
    if (sharedAudioElement === null) {
        sharedAudioElement = new Audio();
        sharedAudioElement.preload = "auto";
        const clearPlaying = () => setPlayerState({ playingSampleKey: null });
        sharedAudioElement.addEventListener("ended", clearPlaying);
        sharedAudioElement.addEventListener("pause", clearPlaying);
    }
    return sharedAudioElement;
};
/** The state now (a new object after every change, so it can back `useSyncExternalStore`). */
export const readAgentVoiceSamplePlayerState = () => playerState;
/** Calls `listener` after every change; returns the unsubscribe. */
export const subscribeAgentVoiceSamplePlayer = (listener) => {
    playerStateListeners.add(listener);
    return () => {
        playerStateListeners.delete(listener);
    };
};
/** The number of subscribers (the hook stops the sample when the last one leaves). */
export const countAgentVoiceSamplePlayerSubscribers = () => playerStateListeners.size;
/** Stops the sample playing, if any. */
export const stopAgentVoiceSample = () => {
    sharedAudioElement?.pause();
    if (playerState.playingSampleKey !== null)
        setPlayerState({ playingSampleKey: null });
};
/** Whether a rejected fetch was a 404 (an RTK Query error, a `Response`-like or any object with `status: 404`). */
export const isAgentVoiceSampleNotFound = (error) => typeof error === "object" && error !== null && "status" in error && error.status === 404;
/**
 * Plays the sample of a voice in a language, or stops it when it is the one playing. Starting a sample stops the one
 * playing; a sample asked for while another loads wins over it. Resolves with why it did not play, or null.
 */
export const toggleAgentVoiceSample = async ({ voiceId, languageCode, fetchSample, }) => {
    const sampleKey = agentVoiceSampleKeyOf(voiceId, languageCode);
    if (playerState.playingSampleKey === sampleKey) {
        stopAgentVoiceSample();
        return null;
    }
    stopAgentVoiceSample();
    let objectUrl = objectUrlBySampleKey.get(sampleKey);
    if (objectUrl === undefined) {
        setPlayerState({ loadingSampleKey: sampleKey });
        let sampleBlob;
        try {
            sampleBlob = await fetchSample(voiceId, languageCode);
        }
        catch (error) {
            if (playerState.loadingSampleKey === sampleKey)
                setPlayerState({ loadingSampleKey: null });
            return isAgentVoiceSampleNotFound(error) ? "notFound" : "loadFailed";
        }
        objectUrl = URL.createObjectURL(sampleBlob);
        objectUrlBySampleKey.set(sampleKey, objectUrl);
        // another sample was asked for while this one loaded: that one wins
        if (playerState.loadingSampleKey !== sampleKey)
            return null;
        setPlayerState({ loadingSampleKey: null });
    }
    const audioElement = audioElementOf();
    audioElement.src = objectUrl;
    setPlayerState({ playingSampleKey: sampleKey });
    try {
        await audioElement.play();
    }
    catch {
        if (playerState.playingSampleKey === sampleKey)
            setPlayerState({ playingSampleKey: null });
        return "playFailed";
    }
    return null;
};
/** Stops the sample and forgets every fetched one (a sign-out, or a spec between tests). */
export const resetAgentVoiceSamplePlayer = () => {
    stopAgentVoiceSample();
    for (const objectUrl of objectUrlBySampleKey.values())
        URL.revokeObjectURL(objectUrl);
    objectUrlBySampleKey.clear();
    sharedAudioElement = null;
    playerState = { playingSampleKey: null, loadingSampleKey: null };
};
