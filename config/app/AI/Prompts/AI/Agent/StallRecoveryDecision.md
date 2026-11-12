This registry entry names the agent's stall-recovery DECISION call for cost booking and usage logging. It is not a prompt: the call goes to a System One decision model (TypeSafe Jev via OpenRouter `/api/v1/systemone`) whose one question is built in code by `DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentStallRecoveryDecision::buildQuestions()` — a `choice` over the moves that apply at the SECOND consecutive stall of an owner turn (the first stall gets the informative nudge; a fold turn is never judged):

- `call_a_work_tool` — work remains, a resident work tool can do it now and its inputs are in the owner's words (the loop forces ONE `tool_choice='required'` re-drive over the eligible work tools — replacing the ladder's own last resort, never adding a call).
- `load_required_capability` — the work is blocked only by a tool or skill that can be loaded (the nudge names that catalog only).
- `end_the_turn_with_text` — the owed response can be written from what is at hand, or the missing input / consent can only come from the owner (the nudge says: answer now, or ask for exactly what you need).
- `none` — undecidable (today's ladder step).

An inapplicable move is removed by PHP before the call. The consequence is the loop's, never inside the question. This text is never sent to the model.
