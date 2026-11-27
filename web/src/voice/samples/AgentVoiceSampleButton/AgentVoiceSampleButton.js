import { LoaderCircle, Play, Square } from "lucide-react";
import { useCallback, useEffect, useSyncExternalStore } from "react";
import { m, useTranslate } from "@mgamadeus/ddd-admin-ui/i18n";
import { cn } from "@mgamadeus/ddd-admin-ui/primitives";
import { agentVoiceSampleKeyOf, countAgentVoiceSamplePlayerSubscribers, readAgentVoiceSamplePlayerState, stopAgentVoiceSample, subscribeAgentVoiceSamplePlayer, toggleAgentVoiceSample, } from "../agent-voice-sample-player";
/** The message of each reason a sample did not play, for the application's toast. */
export const AGENT_VOICE_SAMPLE_ERROR_TEXTS = {
    notFound: m("There is no sample of this voice yet."),
    loadFailed: m("The sample could not be loaded."),
    playFailed: m("The sample could not be played."),
};
/**
 * Plays and stops voice samples; every user of the hook sees the same state (one audio element per page). When the
 * last user leaves the page, the sample stops.
 */
export const useAgentVoiceSamplePlayer = ({ fetchSample, onError }) => {
    const { t } = useTranslate();
    const playerState = useSyncExternalStore(subscribeAgentVoiceSamplePlayer, readAgentVoiceSamplePlayerState, readAgentVoiceSamplePlayerState);
    useEffect(() => {
        const unsubscribe = subscribeAgentVoiceSamplePlayer(() => { });
        return () => {
            unsubscribe();
            if (countAgentVoiceSamplePlayerSubscribers() === 0)
                stopAgentVoiceSample();
        };
    }, []);
    const toggle = useCallback(async (voiceId, languageCode) => {
        const errorKind = await toggleAgentVoiceSample({ voiceId, languageCode, fetchSample });
        if (errorKind !== null)
            onError?.(t(AGENT_VOICE_SAMPLE_ERROR_TEXTS[errorKind]), errorKind);
    }, [fetchSample, onError, t]);
    return {
        toggle,
        isPlaying: (voiceId, languageCode) => playerState.playingSampleKey === agentVoiceSampleKeyOf(voiceId, languageCode),
        isLoading: (voiceId, languageCode) => playerState.loadingSampleKey === agentVoiceSampleKeyOf(voiceId, languageCode),
    };
};
/** Keeps a press on the button from reaching a surrounding select item (which would choose the voice and close). */
const keepFromParent = (event) => {
    event.stopPropagation();
};
/**
 * ▶ / ■ of one sample. Usable inside a select item: a press neither chooses the item nor closes the list. In a select
 * whose value mirrors the item's text, give the select value its own children, so the button is not mirrored.
 */
export const AgentVoiceSampleButton = ({ voiceId, languageCode, voiceName, player, className }) => {
    const { t } = useTranslate();
    const isPlaying = player.isPlaying(voiceId, languageCode);
    const isLoading = player.isLoading(voiceId, languageCode);
    const label = isPlaying
        ? t("Stop the sample of %voiceName%", { replacements: { voiceName } })
        : t("Play the sample of %voiceName%", { replacements: { voiceName } });
    const toggle = (event) => {
        keepFromParent(event);
        event.preventDefault();
        void player.toggle(voiceId, languageCode);
    };
    return (<button type="button" aria-label={label} title={label} aria-pressed={isPlaying} data-voice-sample={agentVoiceSampleKeyOf(voiceId, languageCode)} className={cn("inline-flex size-8 shrink-0 items-center justify-center rounded-full border bg-background text-foreground transition-colors hover:bg-tint focus-visible:outline-2 focus-visible:outline-ring", isPlaying && "border-primary text-primary", className)} onPointerDown={event => {
            keepFromParent(event);
            event.preventDefault();
        }} onPointerUp={keepFromParent} onKeyDown={event => {
            if (event.key === "Enter" || event.key === " ")
                toggle(event);
        }} onClick={toggle}>
      {isLoading ? (<LoaderCircle className="size-4 animate-spin" aria-hidden/>) : isPlaying ? (<Square className="size-3.5 fill-current" aria-hidden/>) : (<Play className="size-3.5 translate-x-px fill-current" aria-hidden/>)}
    </button>);
};
