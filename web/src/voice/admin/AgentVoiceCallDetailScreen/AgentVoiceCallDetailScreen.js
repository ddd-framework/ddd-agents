import { normalizeDddError } from "@mgamadeus/ddd-web/contract";
import { MessagesSquare, RefreshCw } from "lucide-react";
import { formatUiNumber, useTranslate } from "@mgamadeus/ddd-admin-ui/i18n";
import { DateCell } from "@mgamadeus/ddd-admin-ui/list";
import { StatusBadge } from "@mgamadeus/ddd-admin-ui/list";
import { DefinitionList } from "@mgamadeus/ddd-admin-ui/page";
import { PageHeader } from "@mgamadeus/ddd-admin-ui/page";
import { StatusCallout } from "@mgamadeus/ddd-admin-ui/page";
import { Button } from "@mgamadeus/ddd-admin-ui/primitives";
import { Card, CardContent } from "@mgamadeus/ddd-admin-ui/primitives";
import { cn } from "@mgamadeus/ddd-admin-ui/primitives";
import { Skeleton } from "@mgamadeus/ddd-admin-ui/primitives";
import { useLinkComponent } from "@mgamadeus/ddd-admin-ui/primitives";
import { formatAgentVoiceCallDuration } from "../../call/format-agent-voice-call-duration";
import { AgentVoiceCallConversationLink } from "../AgentVoiceCallConversationLink/AgentVoiceCallConversationLink";
import { AgentVoiceCallStateBadges } from "../AgentVoiceCallStateBadges/AgentVoiceCallStateBadges";
import { AGENT_VOICE_ADMIN_ERROR_CALL_NOT_FOUND, AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE, AGENT_VOICE_CALL_DIRECTION, formatAgentVoiceAdminCallCosts, isAgentVoiceAdminError, } from "../agent-voice-admin-format";
/**
 * One voice call: where it stands (the server's reading at answer time) and why it ended, who started it, its account,
 * its conversation, its times (created, answered, ended, the ring deadline), its duration, the booked seconds and
 * their costs, the provider it ran on and its task. The call's conversation opens through the host's link
 * (`buildConversationHref`): nothing of what was said is in this screen. While voice is switched off the screen says
 * so; a call the signed-in account may not read reads as not found. The task is written by the agent or the owner and
 * shown as text only.
 */
export const AgentVoiceCallDetailScreen = ({ api, callId, backLink, headerActions, buildConversationHref, linkComponent, }) => {
    const { t } = useTranslate();
    const Link = useLinkComponent(linkComponent);
    const callQuery = api.useAgentVoiceCallQuery({ callId });
    const callResponse = callQuery.data;
    const call = callResponse?.agentVoiceCall;
    if (callQuery.isLoading) {
        return (<div className="space-y-4">
        <Skeleton className="h-12 w-80"/>
        <Skeleton className="h-32 w-full rounded-2xl"/>
      </div>);
    }
    if (callQuery.isError && isAgentVoiceAdminError(callQuery.error, AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE)) {
        return (<div className="space-y-4" data-testid="agent-voice-call-switched-off">
        <PageHeader title={`#${callId}`} leading={backLink}/>
        <StatusCallout tone="info" title={t("Voice is switched off")} description={t("The voice channel is switched off in this application, so its calls cannot be read.")}/>
      </div>);
    }
    if (!callResponse || !call) {
        const isCallNotFound = !callQuery.isError || isAgentVoiceAdminError(callQuery.error, AGENT_VOICE_ADMIN_ERROR_CALL_NOT_FOUND);
        return (<div className="space-y-4" data-testid="agent-voice-call-not-shown">
        <PageHeader title={`#${callId}`} leading={backLink}/>
        {isCallNotFound ? (<StatusCallout tone="destructive" title={t("This voice call was not found.")} description={t("It does not exist, or it belongs to an account you may not read.")}/>) : (<StatusCallout tone="destructive" title={t("The voice call could not be loaded.")} description={normalizeDddError(callQuery.error, "").message}/>)}
      </div>);
    }
    const conversationId = callResponse.conversationId ?? call.aiConversationId ?? null;
    const conversationHref = conversationId ? (buildConversationHref?.(conversationId) ?? null) : null;
    const startedByAgentInConversationHref = call.startedByAgentInConversationId
        ? (buildConversationHref?.(call.startedByAgentInConversationId) ?? null)
        : null;
    const bookedSeconds = callResponse.bookedSeconds ?? call.bookedSeconds ?? 0;
    const bookedCosts = callResponse.bookedCosts ?? null;
    const durationInSeconds = callResponse.durationInSeconds ?? null;
    return (<div className="space-y-6" data-testid="agent-voice-call-detail-screen">
      <PageHeader leading={backLink} title={t("Voice call")} description={<span className="font-mono text-xs">#{call.id}</span>} actions={<div className="flex flex-wrap items-center gap-2">
            {headerActions}
            <Button type="button" variant="outline" data-testid="agent-voice-call-refresh" onClick={() => void callQuery.refetch?.()} disabled={callQuery.isFetching}>
              <RefreshCw className={cn(callQuery.isFetching && "animate-spin")}/>
              {t("Refresh")}
            </Button>
            {conversationHref && (<Button asChild variant="outline" data-testid="agent-voice-call-open-conversation">
                <Link href={conversationHref}>
                  <MessagesSquare />
                  {t("Open the conversation", { context: "agent-voice-call" })}
                </Link>
              </Button>)}
          </div>}/>
      <Card>
        <CardContent className="pt-6">
          <DefinitionList items={[
            {
                id: "state",
                label: t("State", { context: "agent-voice-call" }),
                value: (<AgentVoiceCallStateBadges callState={callResponse.callState} endReason={callResponse.endReason ?? call.endReason}/>),
            },
            {
                id: "direction",
                label: t("Started by", { context: "agent-voice-call" }),
                value: <StatusBadge value={call.direction} map={AGENT_VOICE_CALL_DIRECTION}/>,
            },
            {
                id: "account",
                label: t("Account", { context: "agent-voice-call" }),
                value: call.accountId ? <span className="font-mono text-xs">#{call.accountId}</span> : null,
            },
            {
                id: "conversation",
                label: t("Conversation", { context: "agent-voice-call" }),
                value: conversationId ? (<AgentVoiceCallConversationLink conversationId={conversationId} href={conversationHref} linkComponent={linkComponent}/>) : null,
                hint: conversationId ? t("Its messages are what was said in the call.") : undefined,
            },
            {
                id: "startedByAgentInConversation",
                label: t("Started by the agent in conversation", { context: "agent-voice-call" }),
                value: call.startedByAgentInConversationId ? (<AgentVoiceCallConversationLink conversationId={call.startedByAgentInConversationId} href={startedByAgentInConversationHref} linkComponent={linkComponent}/>) : null,
            },
            {
                id: "created",
                label: t("Created", { context: "agent-voice-call" }),
                value: call.changeHistory?.createdTime ? <DateCell value={call.changeHistory.createdTime}/> : null,
            },
            {
                id: "answered",
                label: t("Answered", { context: "agent-voice-call" }),
                value: call.startedAt ? <DateCell value={call.startedAt}/> : null,
            },
            {
                id: "ended",
                label: t("Ended", { context: "agent-voice-call" }),
                value: call.endedAt ? <DateCell value={call.endedAt}/> : null,
            },
            {
                id: "ringDeadline",
                label: t("Rings until", { context: "agent-voice-call" }),
                value: call.ringDeadlineAt ? <DateCell value={call.ringDeadlineAt}/> : null,
            },
            {
                id: "duration",
                label: t("Duration", { context: "agent-voice-call" }),
                value: durationInSeconds === null ? null : (<span className="tabular-nums">{formatAgentVoiceCallDuration(durationInSeconds)}</span>),
            },
            {
                id: "bookedSeconds",
                label: t("Booked seconds", { context: "agent-voice-call" }),
                value: <span className="tabular-nums">{formatUiNumber(bookedSeconds)}</span>,
            },
            {
                id: "costs",
                label: t("Costs", { context: "agent-voice-call" }),
                value: bookedCosts ? <span className="tabular-nums">{formatAgentVoiceAdminCallCosts(bookedCosts)}</span> : null,
                hint: !bookedCosts && bookedSeconds > 0 ? t("No price is known for the model this call ran on.") : undefined,
            },
            {
                id: "voiceProvider",
                label: t("Voice provider", { context: "agent-voice-call" }),
                value: call.voiceProvider ? <span className="font-mono text-xs">{call.voiceProvider}</span> : null,
            },
            {
                id: "providerCallId",
                label: t("Provider call ID", { context: "agent-voice-call" }),
                value: call.providerCallId ? <span className="break-all font-mono text-xs">{call.providerCallId}</span> : null,
            },
            {
                id: "task",
                label: t("Task", { context: "agent-voice-call" }),
                wide: true,
                value: call.callTask ? <p className="break-words whitespace-pre-wrap">{call.callTask}</p> : null,
            },
        ]}/>
        </CardContent>
      </Card>
    </div>);
};
