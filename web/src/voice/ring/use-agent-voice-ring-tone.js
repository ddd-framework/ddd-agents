import { useEffect } from "react";
const RING_BURST_SECONDS = 1.2;
const RING_PERIOD_MS = 3000;
const RING_FREQUENCIES_HZ = [440, 480];
const RING_VOLUME = 0.06;
/**
 * A phone-like ring tone while `isRinging`, synthesized with Web Audio (no asset). Browsers block audio until the user
 * has interacted with the page (autoplay policy): without that interaction this plays nothing and the visual ring is
 * the whole signal.
 */
export const useAgentVoiceRingTone = (isRinging) => {
    useEffect(() => {
        if (!isRinging)
            return;
        const userActivation = navigator.userActivation;
        if (userActivation !== undefined && !userActivation.hasBeenActive)
            return;
        if (typeof AudioContext === "undefined")
            return;
        const audioContext = new AudioContext();
        if (audioContext.state === "suspended")
            void audioContext.resume().catch(() => undefined);
        const playRingBurst = () => {
            if (audioContext.state !== "running")
                return;
            const startsAt = audioContext.currentTime;
            const gain = audioContext.createGain();
            gain.gain.setValueAtTime(RING_VOLUME, startsAt);
            gain.gain.setValueAtTime(RING_VOLUME, startsAt + RING_BURST_SECONDS - 0.05);
            gain.gain.linearRampToValueAtTime(0, startsAt + RING_BURST_SECONDS);
            gain.connect(audioContext.destination);
            RING_FREQUENCIES_HZ.forEach((frequencyHz) => {
                const oscillator = audioContext.createOscillator();
                oscillator.frequency.value = frequencyHz;
                oscillator.connect(gain);
                oscillator.start(startsAt);
                oscillator.stop(startsAt + RING_BURST_SECONDS);
            });
        };
        playRingBurst();
        const ringTimer = window.setInterval(playRingBurst, RING_PERIOD_MS);
        return () => {
            window.clearInterval(ringTimer);
            void audioContext.close().catch(() => undefined);
        };
    }, [isRinging]);
};
