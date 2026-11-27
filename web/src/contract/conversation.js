/**
 * The conversation contract of the package: plain TypeScript types the conversation client port
 * (`client/AgentsConversationClient.ts`) returns and the chat reads. Every field is derived from what the chat files and
 * the chat controller read from the server today (the host application's generated `AIConversation`, `AIConversationMessage`,
 * `AgentActivityLine`, `SemanticSearchHit`, `AIConversationSearchGroup`, `AgentCustomerSelectableModel`); nothing the
 * chat does not read is here.
 *
 * Naming: a field keeps the wire name where the name is neutral (`message`, `indexInConversation`, `role`, `status`,
 * `toolCall`); a name that carries an app's vocabulary is renamed (`aiConversationId` → `conversationId`,
 * the app's own key of the thing the agent works for → `agentSpaceKey`, `summary.title` → `title`). Each field carries a
 * one-line note:
 * - `generic` — every app that runs the agent conversation backend has it;
 * - `app extension` — read by the chat today but specific to one app's backend or debug surface; optional, and a
 *   binding may leave it out.
 *
 * Client-side state is NOT part of the contract: the `fresh` tag of a live-arrived message, the optimistic user
 * message without an id and the `isNewConversation` / `isReopenedConversation` flags belong to the package store.
 */
/** Run states after which nothing runs any more and the server refuses further customer input. */
export const CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION = [
    "COMPLETED",
    "CANCELLED",
];
/** Maps a stored run state onto the signal's three-value status (`runStateToSignalStatus` of the live-update hook). */
export const readConversationRunStatusOfRunState = (runState) => {
    if (!runState)
        return null;
    if (runState === "FAILED")
        return "failed";
    if (runState === "RUNNING" || runState === "AWAITING_DELEGATES" || runState === "QUEUED")
        return "running";
    return "idle";
};
/** The caps of {@link AgentsClientContext} (characters; the route parameters as their `name=value&…` text). */
export const AGENTS_CLIENT_CONTEXT_LIMITS = {
    routePathChars: 512,
    routeParametersChars: 512,
    routeParameterCount: 20,
    recordKindChars: 64,
    recordIdChars: 128,
    tabChars: 64,
};
/** The code `other` carries for a request the client cancelled; the chat shows no error card for it. */
export const AGENTS_CLIENT_ERROR_CODE_REQUEST_ABORTED = "REQUEST_ABORTED";
/** The one error shape every port function rejects with. */
export class AgentsClientError extends Error {
    kind;
    /** The server's stable error code, or the client's own code (`REQUEST_ABORTED`); null when none arrived. */
    code;
    /** Seconds until a quota frees up again; set only for `rateLimit`. */
    retryAfterSeconds;
    /** The HTTP status when one arrived (not part of the contract's shape; kept for diagnostics). */
    httpStatus;
    constructor(kind, details = {}) {
        super(details.message ?? kind);
        this.name = "AgentsClientError";
        this.kind = kind;
        this.code = details.code ?? null;
        this.retryAfterSeconds = kind === "rateLimit" ? (details.retryAfterSeconds ?? null) : null;
        this.httpStatus = details.httpStatus ?? null;
    }
}
export const isAgentsClientError = (value) => value instanceof AgentsClientError;
