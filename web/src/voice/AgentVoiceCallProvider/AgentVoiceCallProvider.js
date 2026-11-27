import { useEffect, useMemo } from "react";
import { bindAgentVoiceApi } from "../call/agent-voice-call-store";
import { useAgentVoiceCallServerEnd } from "../call/use-agent-voice-call-server-end";
import { useFollowAgentVoiceCallConversation, } from "../call/use-follow-agent-voice-call-conversation";
import { AgentVoiceCallContext } from "./agent-voice-call-context";
/**
 * Mounted once, above every voice component: binds the adapter to the call store, carries the ring source, ends the
 * call here when the server ended it (the change signal of the call's conversation, from the conversation client in
 * the context above the provider) and reports the call's conversation. The call itself lives in the module's store,
 * not in the provider: it goes on when the provider re-renders or a chat below it closes. The voice components take
 * their texts from the environment around (`AgentsEnvironment.texts`), as every other component of the package.
 */
export const AgentVoiceCallProvider = (props) => {
    const { api, ringSource, onConversationOfCallChanged, children } = props;
    useEffect(() => {
        bindAgentVoiceApi(api);
    }, [api]);
    useAgentVoiceCallServerEnd();
    useFollowAgentVoiceCallConversation(onConversationOfCallChanged);
    const contextValue = useMemo(() => ({ ringSource }), [ringSource]);
    return <AgentVoiceCallContext.Provider value={contextValue}>{children}</AgentVoiceCallContext.Provider>;
};
