import { normalizeDddError } from "@mgamadeus/ddd-web/contract";
import { ChevronLeft, ChevronRight, RefreshCw } from "lucide-react";
import { useState } from "react";
import { formatUiNumber, m, useTranslate } from "@mgamadeus/ddd-admin-ui/i18n";
import { DateCell } from "@mgamadeus/ddd-admin-ui/list";
import { EmptyValue } from "@mgamadeus/ddd-admin-ui/list";
import { EntityListBody } from "@mgamadeus/ddd-admin-ui/list";
import { EntityListCard } from "@mgamadeus/ddd-admin-ui/list";
import { EntityListToolbar } from "@mgamadeus/ddd-admin-ui/list";
import { FilterDateRange, FilterPanel, FilterSelect, FilterText } from "@mgamadeus/ddd-admin-ui/list";
import { StatusBadge } from "@mgamadeus/ddd-admin-ui/list";
import { useDebouncedValue } from "@mgamadeus/ddd-admin-ui/logic";
import { PageHeader } from "@mgamadeus/ddd-admin-ui/page";
import { StatusCallout } from "@mgamadeus/ddd-admin-ui/page";
import { Button } from "@mgamadeus/ddd-admin-ui/primitives";
import { cn } from "@mgamadeus/ddd-admin-ui/primitives";
import { formatAgentVoiceCallDuration } from "../../call/format-agent-voice-call-duration";
import { AgentVoiceCallConversationLink } from "../AgentVoiceCallConversationLink/AgentVoiceCallConversationLink";
import { AgentVoiceCallStateBadges } from "../AgentVoiceCallStateBadges/AgentVoiceCallStateBadges";
import { AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE, AGENT_VOICE_CALL_DIRECTION, AGENT_VOICE_CALL_END_REASON, AGENT_VOICE_CALL_END_REASONS, agentVoiceAdminCallDerivedValuesByCallIdOf, agentVoiceAdminCallDurationInSecondsOf, agentVoiceAdminCallStartTimeOf, formatAgentVoiceAdminCallCosts, isAgentVoiceAdminError, readAgentVoiceAdminWholeNumber, toAgentVoiceAdminDayBound, } from "../agent-voice-admin-format";
/** The most calls one page shows. */
export const AGENT_VOICE_CALLS_MAX_PAGE_SIZE = 200;
const NO_FILTER_VALUES = {
    accountIdText: "",
    createdFromDay: "",
    createdToDay: "",
    bookedSecondsFromText: "",
    endReason: "",
};
const digitsOf = (text) => text.replace(/\D/g, "");
/**
 * The voice calls the signed-in account may read, newest first: when a call began, its account, who started it, where
 * it stands (the server's reading at answer time) and why it ended, its duration, the booked seconds, their costs, its
 * task and its conversation; filters for the account, the day it was created, the booked seconds and the end reason.
 * Needs the admin call routes mounted (`AdminAgentVoiceCallsTrait`). While voice is switched off the
 * screen says so instead of listing. A call's task is written by the agent or the owner and shown as text only.
 */
export const AgentVoiceCallsScreen = ({ api, title, description, headerActions, onOpenCall, accountId, conversationId, buildConversationHref, linkComponent, pageSize = 50, }) => {
    const { t } = useTranslate();
    const [filterValues, setFilterValues] = useState(NO_FILTER_VALUES);
    const [offset, setOffset] = useState(0);
    const debouncedAccountIdText = useDebouncedValue(filterValues.accountIdText);
    const debouncedBookedSecondsFromText = useDebouncedValue(filterValues.bookedSecondsFromText);
    const callsPerPage = Math.min(Math.max(Math.floor(pageSize), 1), AGENT_VOICE_CALLS_MAX_PAGE_SIZE);
    const isAccountFilterShown = accountId === undefined;
    // The answer carries no total count: one call more than a page shows tells whether an older page exists.
    const listQuery = api.useAgentVoiceCallsQuery({
        accountId: accountId ?? readAgentVoiceAdminWholeNumber(debouncedAccountIdText),
        conversationId,
        createdFrom: toAgentVoiceAdminDayBound(filterValues.createdFromDay, false),
        createdTo: toAgentVoiceAdminDayBound(filterValues.createdToDay, true),
        bookedSecondsFrom: readAgentVoiceAdminWholeNumber(debouncedBookedSecondsFromText),
        endReason: filterValues.endReason === "" ? undefined : filterValues.endReason,
        limit: callsPerPage + 1,
        offset,
    });
    const loadedCalls = listQuery.data?.agentVoiceCalls?.elements ?? [];
    const calls = loadedCalls.slice(0, callsPerPage);
    const hasOlderCalls = loadedCalls.length > callsPerPage;
    const derivedValuesByCallId = agentVoiceAdminCallDerivedValuesByCallIdOf(listQuery.data?.agentVoiceCallDerivedValues);
    const isVoiceSwitchedOff = listQuery.isError && isAgentVoiceAdminError(listQuery.error, AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE);
    const changeFilterValues = (changedFilterValues) => {
        setFilterValues((currentFilterValues) => ({ ...currentFilterValues, ...changedFilterValues }));
        setOffset(0);
    };
    const activeFilterCount = [
        isAccountFilterShown && filterValues.accountIdText !== "",
        filterValues.createdFromDay !== "" || filterValues.createdToDay !== "",
        filterValues.bookedSecondsFromText !== "",
        filterValues.endReason !== "",
    ].filter(Boolean).length;
    const clearFilters = () => changeFilterValues(NO_FILTER_VALUES);
    const columns = [
        {
            id: "started",
            header: t("Started", { context: "agent-voice-call" }),
            width: "content",
            cell: (call) => <DateCell value={agentVoiceAdminCallStartTimeOf(call)}/>,
        },
        {
            id: "account",
            header: t("Account", { context: "agent-voice-call" }),
            width: "content",
            cell: (call) => (call.accountId ? <span className="font-mono text-xs">#{call.accountId}</span> : <EmptyValue />),
        },
        {
            id: "direction",
            header: t("Started by", { context: "agent-voice-call" }),
            width: "content",
            cell: (call) => <StatusBadge value={call.direction} map={AGENT_VOICE_CALL_DIRECTION}/>,
        },
        {
            id: "state",
            header: t("State", { context: "agent-voice-call" }),
            width: "content",
            cell: (call) => (<AgentVoiceCallStateBadges callState={derivedValuesByCallId.get(call.id)?.callState} endReason={call.endReason}/>),
        },
        {
            id: "duration",
            header: t("Duration", { context: "agent-voice-call" }),
            width: "content",
            align: "end",
            cell: (call) => {
                const durationInSeconds = agentVoiceAdminCallDurationInSecondsOf(call);
                return durationInSeconds === null ? (<EmptyValue />) : (<span className="tabular-nums">{formatAgentVoiceCallDuration(durationInSeconds)}</span>);
            },
        },
        {
            id: "bookedSeconds",
            header: t("Booked seconds", { context: "agent-voice-call" }),
            width: "content",
            align: "end",
            cell: (call) => <span className="tabular-nums">{formatUiNumber(call.bookedSeconds ?? 0)}</span>,
        },
        {
            id: "costs",
            header: t("Costs", { context: "agent-voice-call" }),
            width: "content",
            align: "end",
            cell: (call) => {
                const bookedCosts = derivedValuesByCallId.get(call.id)?.bookedCosts;
                return bookedCosts ? <span className="tabular-nums">{formatAgentVoiceAdminCallCosts(bookedCosts)}</span> : <EmptyValue />;
            },
        },
        {
            id: "task",
            header: t("Task", { context: "agent-voice-call" }),
            width: "2fr",
            cell: (call) => call.callTask ? (<span className="block truncate" title={call.callTask}>
            {call.callTask}
          </span>) : (<EmptyValue />),
        },
        {
            id: "conversation",
            header: t("Conversation", { context: "agent-voice-call" }),
            width: "content",
            cell: (call) => {
                if (!call.aiConversationId)
                    return <EmptyValue />;
                const conversationHref = buildConversationHref?.(call.aiConversationId) ?? null;
                return (<span data-row-action-ignore>
            <AgentVoiceCallConversationLink conversationId={call.aiConversationId} href={conversationHref} linkComponent={linkComponent}/>
          </span>);
            },
        },
    ];
    const endReasonOptions = AGENT_VOICE_CALL_END_REASONS.map((endReason) => ({
        value: endReason,
        label: t(AGENT_VOICE_CALL_END_REASON[endReason].label, { context: AGENT_VOICE_CALL_END_REASON[endReason].context }),
    }));
    return (<div className="space-y-6" data-testid="agent-voice-calls-screen">
      <PageHeader title={title ?? t("Voice calls")} description={description ??
            t("The voice chats the owners started with the agent and the calls the agent rang them with, newest first.")} actions={<div className="flex flex-wrap items-center gap-2">
            {headerActions}
            <Button type="button" variant="outline" data-testid="agent-voice-calls-refresh" onClick={() => void listQuery.refetch?.()} disabled={listQuery.isFetching}>
              <RefreshCw className={cn(listQuery.isFetching && "animate-spin")}/>
              {t("Refresh")}
            </Button>
          </div>}/>
      {isVoiceSwitchedOff ? (<div data-testid="agent-voice-calls-switched-off">
          <StatusCallout tone="info" title={t("Voice is switched off")} description={t("The voice channel is switched off in this application, so its calls are not listed.")}/>
        </div>) : (<>
          <EntityListCard label={m("Voice calls")} isRefetching={listQuery.isFetching && !listQuery.isLoading} toolbar={<EntityListToolbar activeFilterCount={activeFilterCount} onClearFilters={clearFilters} filters={<FilterPanel>
                    {isAccountFilterShown && (<FilterText label={t("Account", { context: "agent-voice-call-filter" })} placeholder={t("Account ID", { context: "agent-voice-call-filter" })} inputMode="numeric" value={filterValues.accountIdText} onChange={(accountIdText) => changeFilterValues({ accountIdText: digitsOf(accountIdText) })}/>)}
                    <FilterDateRange label={t("Created", { context: "agent-voice-call-filter" })} from={filterValues.createdFromDay} to={filterValues.createdToDay} onFromChange={(createdFromDay) => changeFilterValues({ createdFromDay })} onToChange={(createdToDay) => changeFilterValues({ createdToDay })}/>
                    <FilterText label={t("Booked seconds, at least", { context: "agent-voice-call-filter" })} placeholder={t("Seconds", { context: "agent-voice-call-filter" })} inputMode="numeric" value={filterValues.bookedSecondsFromText} onChange={(bookedSecondsFromText) => changeFilterValues({ bookedSecondsFromText: digitsOf(bookedSecondsFromText) })}/>
                    <FilterSelect label={t("End reason", { context: "agent-voice-call-filter" })} allLabel={t("All", { context: "agent-voice-call-filter" })} options={endReasonOptions} value={filterValues.endReason} onChange={(endReason) => changeFilterValues({ endReason })}/>
                  </FilterPanel>}/>}>
            <EntityListBody displayMode="table" columns={columns} items={calls} getRowKey={(call) => String(call.id)} rowAction={onOpenCall ? (call) => () => onOpenCall(call.id) : undefined} isLoading={listQuery.isLoading} emptyMessage={t("No voice call yet: a call starts when an owner opens a voice chat or the agent rings.")} noResultsMessage={t("No voice call matches these filters.")} isSearchActive={activeFilterCount > 0} onClearSearch={clearFilters} loadingMessage={t("Loading the voice calls")} error={listQuery.isError
                ? normalizeDddError(listQuery.error, t("The voice calls could not be loaded.")).message
                : undefined}/>
          </EntityListCard>
          {(offset > 0 || hasOlderCalls) && (<div className="flex items-center justify-end gap-2">
              <Button type="button" variant="outline" size="sm" data-testid="agent-voice-calls-newer" disabled={offset === 0 || listQuery.isFetching} onClick={() => setOffset(Math.max(0, offset - callsPerPage))}>
                <ChevronLeft />
                {t("Newer", { context: "agent-voice-pagination" })}
              </Button>
              <Button type="button" variant="outline" size="sm" data-testid="agent-voice-calls-older" disabled={!hasOlderCalls || listQuery.isFetching} onClick={() => setOffset(offset + callsPerPage)}>
                {t("Older", { context: "agent-voice-pagination" })}
                <ChevronRight />
              </Button>
            </div>)}
        </>)}
    </div>);
};
