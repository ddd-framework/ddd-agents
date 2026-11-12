This registry entry names the agent's reply-language DECISION call for cost booking and usage logging. It is not a prompt: the call goes to a System One decision model (TypeSafe Jev via OpenRouter `/api/v1/systemone`) whose questions are built in code by `DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision::buildQuestions()` — three narrow typed questions over the owner's own recent words and the rendered standing-directive block:

- `written_language` — which language the words in `ownerWordsThisTurn` are written in (with `undecidable`).
- `requested_language` — which language the owner explicitly asks to be answered in (with `none`).
- `directive_language` — which reply language the standing instructions in `standingDirectives` prescribe (with `none` / `conflicting`).

The precedence between them is resolved in PHP (`AgentReplyLanguageDecision::resolveByRecency()`, recency), never inside a question. This text is never sent to the model.

The same request carries a fourth site when no plan with open items is active on an owner-text turn: `needs_working_plan` (`ArgusAgentTurnStartDecision::buildPlanDynamicsQuestions()`) — does fulfilling `ownerMessage` require a written working plan of several separate work items? PHP resolves three bands (required at P ≥ 0.80, direct at P ≤ 0.10, middle between) and renders the chat model's `[SIGNAL:plan]` directive only while the site's second switch ("the model receives the verdict") is on; the middle band is re-asked once mid-turn (`AI.Agent.PlanDynamicsDecision`).

The skill-preload site adds ONE question to the same request on an owner-text turn: `skill` (`ArgusAgentTurnStartDecision::buildPreloadQuestions()`) — which offered skill is the one to load for `ownerMessage`, with the abstention `none`. A skill is loaded for its domain; whether a tool will be called is not asked (owner 2026-09-27). PHP applies the pick when the best skill reaches 0.60 while `none` stays ≤ 0.40 over the validated distribution (`AgentSkillPreloadDecision::resolvePreloadFromDistribution()`, owner 2026-10-02).

The sub-skill pass of the preload site asks its own question, `ArgusAgentTurnStartDecision::buildSubSkillPreloadQuestions()`: the skill `loadedSkill` has been loaded and includes the sub-skills in `subSkills` — does loading one of them make sense for `ownerMessage`, with the abstention `none`. It runs as the follow-up of a root the roots pass admitted, and as a parallel request per skill that was loaded in an earlier turn and still has sub-skills to offer. The loaded skill is sent by name only.

