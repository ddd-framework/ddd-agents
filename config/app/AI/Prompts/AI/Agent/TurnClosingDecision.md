This registry entry names the agent's turn-closing DECISION call for cost booking and usage logging. It is not a prompt: the call goes to a System One decision model (TypeSafe Jev via OpenRouter `/api/v1/systemone`) whose questions are built in code by `DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnClosingDecision::buildQuestions()` — two `noul` questions over the assistant's final answer of a turn that ran no work tool while plan items still owe tool or widget evidence:

- `announces_instead_of_delivers` — the answer announces or promises the open work as the assistant's own next step instead of doing it, asking the owner what it needs first, or saying plainly it is not done.
- `reports_completed_writes` — the answer reports changes as already made this turn (recorded for the gate measurement, never a bounce trigger on its own).

The consequence (bounce or accept) is decided in PHP (`AgentTurnClosingDecision::resolveWouldBounce()`), never inside a question. This text is never sent to the model.
