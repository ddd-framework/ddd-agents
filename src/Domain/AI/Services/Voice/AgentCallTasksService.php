<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskOutcome;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskStart;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The ONE bridge between a voice model and the agent: a request of the voice model becomes a turn of the call's
 * conversation through {@see AIConversationAgentService::driveConversation()} (a turn-owing signal on the transcript,
 * never a shortcut into the loop), and the turn's outcome is read back from the conversation. All state is derived
 * from the CONVERSATION (run state and message indexes) — never from worker memory, so a successor worker gets the
 * same answers as the worker that started the task.
 *
 * Override points for an application's same-path subclass: the text of the start signal
 * ({@see buildDelegationStartSignalText()}) and its stored source value ({@see getDelegationSignalSource()}).
 */
class AgentCallTasksService extends Service
{
    /** @var string A start while a turn still runs on the conversation — the task was NOT queued. */
    public const string STATUS_BUSY = AgentCallTaskStart::STATUS_BUSY;

    /**
     * A worker process can start WITHOUT an auth account: its attach message was dispatched before anybody signed in.
     * Every message the worker dispatches afterwards (the delegated turn, the post-call memory curation) captures the
     * process's auth account — none — and the turn worker then runs as nobody: rights-scoped reads answer "not found
     * or not authorized". The account of the call is the conversation's account, so once the call has a conversation
     * the process runs under that account for the rest of the call — a process whose message already carried an
     * account is left untouched.
     */
    public function ensureAuthAccountForConversation(AIConversation $aiConversation): void
    {
        $conversationAccountId = (int)($aiConversation->accountId ?? 0);
        if ($conversationAccountId > 0 && AuthService::instance()->getAccount() === null) {
            AuthService::instance()->setAccountId($conversationAccountId);
        }
    }

    /**
     * Start an agent task whose request is ALREADY in the conversation — the transcript rows the call's worker wrote.
     * A delegation of the voice model carries no text, and copying the caller's sentences into a task message would
     * double every request in the chat; so the turn is driven by a turn-owing SIGNAL alone, and everything after that
     * signal's index is the agent's work. Busy rule: a start while a turn is RUNNING would race the turn worker on the
     * message index, so it is refused on the FRESH run state ({@see STATUS_BUSY}) and nothing is queued.
     *
     * @return AgentCallTaskStart started with the signal's index, or the refusal (busy, or an error)
     */
    public function startAgentTaskOnTranscript(AIConversation $aiConversation): AgentCallTaskStart
    {
        $this->ensureAuthAccountForConversation($aiConversation);
        $freshConversation = $this->findFreshConversation($aiConversation);
        if (!$freshConversation instanceof AIConversation) {
            return AgentCallTaskStart::forError(AgentCallTaskStart::ERROR_CONVERSATION_NOT_FOUND);
        }
        if ($freshConversation->getRunState()->isNonTerminal()) {
            return AgentCallTaskStart::forBusy();
        }

        $delegationSignalSource = $this->getDelegationSignalSource();
        $drivenConversation = $this->getAIConversationAgentService()->driveConversation(
            AIConversationDriveScope::ADMIN,
            (int)$aiConversation->id,
            null,
            '',
            async: true,
            startSignalText: $this->buildDelegationStartSignalText(),
            startSignalSource: $delegationSignalSource
        );
        // The signal IS the task boundary: the highest delegation signal is this one.
        $taskSignalIndex = -1;
        foreach ($drivenConversation->messages->getElements() as $conversationMessage) {
            if (($conversationMessage->messageType ?? '') === AIConversationMessage::MESSAGE_SIGNAL
                && ($conversationMessage->signalSource ?? '') === $delegationSignalSource
            ) {
                $taskSignalIndex = max($taskSignalIndex, (int)($conversationMessage->indexInConversation ?? -1));
            }
        }

        return AgentCallTaskStart::forStarted(
            $taskSignalIndex >= 0 ? $taskSignalIndex : max(0, $drivenConversation->messages->getNextIndex() - 1)
        );
    }

    /**
     * The settled-or-not state of the task whose start sits at $taskAfterMessageIndex, read FRESH from the database
     * (the turn runs in another worker): the conversation's run state plus the ASSISTANT TEXT rows after that index.
     * IDLE without an answer counts as working — the dispatched resume has not started yet. The run state is read
     * first: while the turn runs, the poll is that one row read — the message tail is read only once the run is
     * terminal (the session worker polls every 0.5 s while a task runs).
     *
     */
    public function findAgentTaskOutcome(AIConversation $aiConversation, int $taskAfterMessageIndex): AgentCallTaskOutcome
    {
        $freshConversation = $this->findFreshConversation($aiConversation);
        if (!$freshConversation instanceof AIConversation) {
            return AgentCallTaskOutcome::forFailed();
        }
        if ($freshConversation->getRunState()->isNonTerminal()) {
            return AgentCallTaskOutcome::forWorking();
        }

        $latestAssistantAnswerText = null;
        $tailMessages = $freshConversation->findRawTailMessagesInOrder(
            $taskAfterMessageIndex,
            useEntityRegistrCache: false
        );
        foreach ($tailMessages?->getElements() ?? [] as $tailMessage) {
            if ($tailMessage->isAssistantText()
                && trim((string)($tailMessage->message ?? '')) !== ''
            ) {
                $latestAssistantAnswerText = WidgetBlocks::buildModelFacingText((string)$tailMessage->message);
            }
        }

        if ($freshConversation->runState === AIConversationRunState::FAILED) {
            return AgentCallTaskOutcome::forFailed($latestAssistantAnswerText);
        }
        if ($latestAssistantAnswerText !== null) {
            return AgentCallTaskOutcome::forCompleted($latestAssistantAnswerText);
        }

        return AgentCallTaskOutcome::forWorking();
    }

    /**
     * What the agent reads as the reason of the turn a delegation starts: the request is the caller's last words in
     * the transcript, the answer will be spoken, and a transcript of speech may garble names and numbers.
     */
    protected function buildDelegationStartSignalText(): string
    {
        return 'The caller, on a voice call with you right now, asked you to take care of what they just said —'
            . ' their request is the last thing they said in the transcript above. Do it now, and answer as'
            . ' if you were speaking to them. Their lines are a transcript of speech: a name, a place, a street,'
            . ' a website or a number in them may be garbled — where the assistant\'s own read-back sentence'
            . ' next to it restates the same thing, that restatement is the fact. An e-mail address, a phone'
            . ' number or a web address that only comes from this transcript and is no known contact is read back'
            . ' to them once, spelled out, before anything is sent to it (that read-back is the one confirmation).'
            . ' Words plainly addressed to other people in the room (side talk, often in another language than'
            . ' the call\'s) are no request to you: do nothing and answer nothing. If what they just said ends'
            . ' the call ("bye", "that\'s all", "hang up"), end it with endVoiceCall (skill'
            . ' Agent.Voice) and answer with one short goodbye.';
    }

    /**
     * The stored source value of the signal that starts a delegated turn: the value conversations already carry
     * ({@see PhoneConversationSignalSource::PHONE_DELEGATION}).
     */
    protected function getDelegationSignalSource(): string
    {
        return PhoneConversationSignalSource::PHONE_DELEGATION->value;
    }

    /** The conversation as stored now (no registry cache): the turn runs in another worker. */
    protected function findFreshConversation(AIConversation $aiConversation): ?AIConversation
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $freshConversation = $aIConversationsService->find((int)$aiConversation->id, false);

        return $freshConversation instanceof AIConversation ? $freshConversation : null;
    }

    /** The container's {@see AIConversationAgentService}; a test replaces it here. */
    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);

        return $aIConversationAgentService;
    }
}
