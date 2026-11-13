# Config map: the fourteen config files and who explains them

**Reader:** host developer

Contents: how a config file is replaced · the map · the four files of this skill · environment variables.

## How a config file is replaced

The package's config root is `config/app/` (`AgentsModule::getConfigPath()`). The framework searches the
application's config roots first, then the modules' (`Config::get()`), so a file the host puts at the same path
**replaces the package's file whole**: keys are never merged. A host that changes one key copies the whole file.
Readers that fall back per key to their class default: `AgentApplicationWording::current()`,
`AgentApplicationNames`, `ContextRetentionSettings`, `SupervisorSettings` and `AgentDecisionModelSettings` (a site the
file does not list is off, except `AgentDecisionModelSettings::DEFAULT_ON_SITES`). The other readers take the file as
it is.

Besides the fourteen PHP files the package ships prompts (`config/app/AI/Prompts/`), purposes
(`config/app/AI/Purposes/`) and two agent skill files (`config/app/AI/Skills/`); a host's file at the same path
replaces each of them the same way. Prompts: `ddd-module-agents-context-specialist` and
`ddd-module-agents-decisions-and-guards-specialist`; purposes and skills: `ddd-module-agents-skills-and-purposes-specialist`
and `ddd-module-agents-autonomy-specialist`.

## The map

| File under `config/app/AI/` | Config key | Read by | What it sets | Default | Explained in |
|---|---|---|---|---|---|
| `agentApplicationNames.php` | `AI.agentApplicationNames` | `AgentApplicationNames` | trace line prefix, console label, further environment variable names | `[agent:trace]`, `agent`, none | this file |
| `agentApplicationWording.php` | `AI.agentApplicationWording` | `AgentApplicationWording::current()` | the host's words in the module's sentences | neutral words (`workspace`, `subscription`, empty examples) | this file |
| `agentEgress.php` | `AI.agentEgress` | `ArgusAIAgentConversation` | the HTTP transfer of the agent's model call | stall abort off, no proxy | this file |
| `agentPersona.php` | `AI.agentPersona` | `ArgusAIAgentConversation::findAgentPersonaNames()`, `AISkillsService::getAgentPersona()` | the agent's name and the product name in the prompts and in the skill and purpose texts | `Assistant`, `this product` | this file |
| `agentToolCallChildProcess.php` | `AI.agentToolCallChildProcess` | `AgentToolCallForkJoinService` | the host command that runs one tool call in its own process | none: every call runs inline | `ddd-module-agents-tools-specialist` |
| `contextRetention.php` | `AI.contextRetention` | `ContextRetentionSettings` | skill unloading, skill loading strategy, state delivery | skill granularity, append loading | `ddd-module-agents-context-specialist` |
| `decisionModel.php` | `AI.decisionModel` | `AgentDecisionModelSettings::current()` | the decision layer's mode and sites | mode `off` | `ddd-module-agents-decisions-and-guards-specialist` |
| `evalOutboundRecipients.php` | `AI.evalOutboundRecipients` | `EvalOutboundRecipientsService` | the test sinks an eval run may send to | none | `ddd-module-agents-observability-specialist` |
| `purposeRequirements.php` | `AI.purposeRequirements` | `AIConversationPurposesService::getPurposeByName()` | feature flags a purpose requires | none | `ddd-module-agents-skills-and-purposes-specialist` |
| `skillNamespaceGroups.php` | `AI.skillNamespaceGroups` | `AISkillCatalogDocService` | the groups of the admin skill screen | the package's groups | `ddd-module-agents-skills-and-purposes-specialist` |
| `skillPackages.php` | `AI.skillPackages` | `AISkillsService`, `AISkill::getPackageClassesBySkillName()` | which MCP packages each skill brings | the package's own skills | `ddd-module-agents-skills-and-purposes-specialist` |
| `skillRequirements.php` | `AI.skillRequirements` | `AISkillsService::applySkillRequirementOverlay()` | flags, rights and billing access a skill requires | none | `ddd-module-agents-skills-and-purposes-specialist` |
| `supervisor.php` | `AI.supervisor` | `SupervisorCheckpointService` | the supervisor switch, model and efforts | off | `ddd-module-agents-autonomy-specialist` |
| `usageScopesCountedElsewhere.php` | `AI.usageScopesCountedElsewhere` | `DDD\Domain\AI\Services\AIUsageEnvelopesService` (ddd-ai) | usage scopes left out of booked-cost recordings | the chat model's turn call | `ddd-module-agents-loop-specialist` |

## The four files of this skill

### `agentApplicationNames.php`

| Key | Meaning |
|---|---|
| `traceLinePrefix` | the prefix of every trace line the module writes to the log (existing log filters of a host match it) |
| `consoleAssistantLabel` | the label in front of the agent's lines on the console (`<label>> text`) |
| `furtherEnvironmentVariableNames` | per environment variable of the module a further name the host's machines set it under, read after the module's own name: `['AGENT_TRACE' => 'SHOP_TRACE']` (the example of the package's own file) |

### `agentApplicationWording.php`

The words the module puts into its sentences for the model and the owner. Every key is listed in
`AgentApplicationWording::DECLARED_PROPERTY_NAMES` with its default on the class; a key the host leaves out, leaves
empty or sets to a non-string keeps the default. Groups:

- **The space and the billed entity:** `billingEntityName`, `agentSpaceName`, `agentSpaceContextLabel`,
  `agentSpaceContextStateKind`, `agentSpaceContextSubjectName`, `agentSpaceFactsStateName`,
  `agentSpaceOwnDataExamples`, `agentSpaceQuestionTopicExamples`. The host's base prompt writes the same
  `agentSpaceContextLabel` in front of `{%AGENT_SPACE_CONTEXT%}`. Changing `agentSpaceContextStateKind` later stops the
  reading of the notes stored under the earlier kind.
- **Owner consent and outward actions:** `ownerConsentSameTargetExamples`,
  `ownerConsentSameTargetWrittenDifferentlyExample`, `ownerConsentDifferentTargetExamples`, `outwardActionKinds`,
  `outwardActionConsentException`.
- **Runs, plans and purposes:** `workContinuedAfterOwnerMessageName`, `seededPlanConversationName`,
  `seededPlanBackgroundWorkExamples`, `purposeBoundSkillLoadAdvice`, `criterionRecordName`,
  `criterionStageToolExamples`, `runReportNotApplicableItemsSentence` (a translation key with `%items%`).
- **Decisions:** `skillPreloadFactsMeaning`, `replyLanguageFurtherCoveredSentences`, `identityOrClassProposalSubject`,
  `identityOrClassRejectedCriterion`, `identityOrClassNotRejectedCriterion`, `assistantKindDescription`.

An empty example key leaves the examples out of the sentence. Which sentence reads which key: the docblock of each
property of `AgentApplicationWording`.

### `agentEgress.php`

| Key | Meaning |
|---|---|
| `transferStallAbort` | `bytesPerSecond` and `seconds`: the transfer of a model call ends as a transport failure when fewer bytes per second arrive on average over that many seconds; `seconds` 0 switches it off |
| `liteLlmEndpoint` | the load endpoint of a proxy egress (`POST:https://…/chat/completions`) that `AGENT_EGRESS=litellm` selects per process, for tests only; null ignores the switch with a log line |

### `agentPersona.php`

| Key | Meaning |
|---|---|
| `agentName` | the agent's name, `{%AGENT_NAME%}` in the base prompt and the character prompt |
| `productName` | the product name when the space service names none for a conversation (`{%PRODUCT_NAME%}`); `AgentSpacesService::findProductNameOfConversation()` answers per conversation |

## Environment variables

`AgentEnvironmentVariables` names the variables the module reads. Each reader reads the module's name first, then the
further name `agentApplicationNames.php` declares for it.

| Variable | Effect |
|---|---|
| `AGENT_TRACE` | `1` / `true`: the agent's trace lines are written to the log (local runs) |
| `AGENT_EGRESS` | `litellm`: this process's agent model calls go through `liteLlmEndpoint` (tests) |
| `AGENT_UNLOAD_GRANULARITY` | `skill` / `tool`: overrides `unloadGranularity` of `AI.contextRetention` |
| `AGENT_SUB_SKILL_TOOL_DISCOVERY` | the sub-skill tool discovery mode (`ddd-module-agents-skills-and-purposes-specialist`) |
