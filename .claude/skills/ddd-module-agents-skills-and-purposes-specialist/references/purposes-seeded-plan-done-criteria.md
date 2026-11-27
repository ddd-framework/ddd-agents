# Purposes, the seeded plan and done criteria

**Reader:** host developer and package maintainer

Contents: what a purpose is · the three parts of a purpose · the purpose file keys · inheritance · starting a
purposed conversation · the hooks of the purpose class · the seeded task plan · done criteria · the customer progress
stepper · hidden purposes · a run nobody reads · reading purposed runs · the package's purposes and the purpose of a scheduled run

## What a purpose is

A purpose starts a conversation FOR a declared goal and contributes data, stamped once at creation: a directive
(system segment), preloaded and pinned skills, a skill rail, run settings, a seeded task plan, a kickoff signal, done
criteria, a stepper. It is not a skill: it is never in the `load_skills` catalog and cannot be loaded mid-chat. The
conversation keeps its `purposeName`; everything else is read from the purpose when needed. A later edit of the
purpose file changes the next conversation, not the settings a running one was stamped with.

## The three parts of a purpose

| Part | Where | Required |
|---|---|---|
| The file | `config/app/AI/Purposes/<Domain>/<Feature>.md` of a config root; the dotted path is the name; YAML frontmatter + the directive body | yes |
| The class | named by the file's `purposeClass`, a subclass of `AIConversationPurpose`, in the purpose's own domain (`Domain/<Domain>/Entities/AI/Conversations/Purposes/`) | yes, even when it adds nothing |
| The registration | the name in `REGISTERED_PURPOSE_NAMES` of the host's same-path subclass of `AIConversationPurpose`, redeclared with the package's names included | yes for the space-scoped start and the phone attach |

The classes a host extends or calls, by full name: `DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose`,
`DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion`,
`DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService`.

`AIConversationPurposesService::getPurposeByName()` loads it (config `AI.Purposes.<name>`). A missing file answers
null. A missing or invalid `purposeClass` is logged as an error and also answers null (throws with
`AIConversationPurposesService::$throwErrors`): there is no fallback to the base class, because a purpose without its
class would run without its criteria and close logic. `AIConversationPurposeRunsService::findStartablePurposeForAgentSpace()` (the space-scoped start) and
`AIConversationPurposeRunsService::attachPurposeToPhoneConversation()` refuse a name that
`AIConversationPurpose::isRegisteredPurposeName()` does not know; `AIConversationAgentService::driveConversation()`
with a purpose name does not check the registry, so its caller validates the name. `AIConversationPurpose::getAllPurposeNames()` and
the other lists are read from the application's class through `AIConversationPurpose::getPurposeClassOfTheApplication()`,
never from the class a caller names. The host's registry class is found by the framework's same-path lookup: under
`App\` at the mirrored path with no configuration, under another root namespace through a `services.yaml` definition
whose id is the package class (`ddd-module-agents-specialist`, override points).

Templates: [templates/purpose.md](../templates/purpose.md), [templates/PurposeClass.php](../templates/PurposeClass.php), [templates/PurposeNameRegistry.php](../templates/PurposeNameRegistry.php).

Write `purposeClass` plain or single-quoted: inside double quotes the backslashes are YAML escapes, the frontmatter
does not parse and every read of the purpose throws the parse error (it is not answered with null).

## The purpose file keys

All read by `AIConversationPurposesService::getPurposeByName()`. Unlike skill files, purpose keys have no lint: a
misspelt key is silently ignored.

| Key | Effect |
|---|---|
| `purposeClass` | the class; read from the file's OWN frontmatter, never inherited |
| `description` | one line: what the conversation is for |
| `inheritsFrom` | another purpose whose keys this file inherits (below) |
| `preloadSkillNames` | skills loaded on the kickoff signal, flag- and right-filtered |
| `pinPreloadedSkills` | default true: the preloaded skills are never evicted |
| `allowedLoadSkillNames` | the rail: when non-empty, only these (plus the preloaded ones, so a dropped root can be reloaded) can be offered or loaded |
| `seedTaskPlanItems` | the seeded plan (below) |
| `customerProgressSteps` | the stepper declaration (below) |
| `kickoffSignalText` | the invisible signal that drives turn one (the agent speaks first); default "Begin this purposed conversation. Greet the owner first." |
| `requiredFeatureFlags` | flags a customer start needs; united with `AI.purposeRequirements` |
| `startableViaApi` | default true; false = only the platform spawns it |
| `completedConversationStaysSingleton` | a finished conversation stays the space's singleton and is reopened for reading |
| `blockedToolNames`, `blockedToolsRefusalText` | tools refused for the conversation and its whole delegation tree, named in the directive block. The purpose class adds the tools it blocks by what they declare (`AIConversationPurpose::getToolNamesBlockedByDeclaration()`, none by default) |
| `maxStepsPerTurn` | the per-turn step cap, copied into the run settings at the start |
| `maxTurnCostUsd` | the cost cap (USD) of every turn of the purpose's conversations, read live by `AIConversationTurnBudget`; an explicit `RunSettings::$maxTurnCostUsd` wins; unset = a raised turn is capped at 2.00, a plain turn not at all |
| `seededTaskPlanAcceptsNewItems` | `true`: the agent may add items and subtasks to the seeded plan with `update_task_plan` (the seeded items stay fixed: no rename, drop, move or reorder); default false: the seeded plan is worked, never written |
| `stepsPerPlanItem` | steps a planned turn gets per open plan leaf, 1–20 (default 3): work whose items each take several tool calls |
| `plannedTurnMaxStepsCeiling` | an optional ceiling of a planned turn's steps (unset: none, the cost cap is the brake) and of a tool's or skill's step floor (unset: 60), at most 120 |
| `contextBudgetTokens`, `contextRetention` | the working-context budget and the retention knobs (context skill) |
| `modelInputOptions` | a map of the keys of `config/app/AI/agentModelInputOptions.php` (`offersDelegationTools`, `offersTaskPlanTools`, `offersAskOwner`, `offersFindWidgets`, `teachesWidgets`, `droppedBasePromptSections`, `dropsMemorySectionWithoutMemoryTool`, `offersReachableToolsFromTheFirstCall`): each key it names wins over the host's configuration for the purpose's conversations, a key it leaves out keeps the configured value (`AgentModelInputOptionsOverride`, context skill) |
| `runSettings` | wire API and reasoning carry-over defaults of a new conversation (`AIConversationPurposesService::buildPurposeRunSettingsFromFrontmatter()`); with the creation choices of `contextRetention` the "agent wire profile", applied whole or not at all |
| `preferredModelName`, `reasoningEffort`, `minReasoningEffort`, `fastMode` | the model and effort of the purpose's calls; the two efforts apply while the decision site `reasoningEffort` is `shadow` or `on`, under `off` the catalog's effort is sent (decisions skill) |
| `worksAutonomouslyAfterKickoff` | the run works without owner input after the kickoff |
| `usesSynthesizedOwnerText`, `loadsEarlierSessionsItself`, `readsLocalSituation` | what the context build may assume about the user rows and the history |
| `commonPitfalls`, `supervisorCadence`, `supervisorRunCap` | supervision (autonomy skill) |
| `analysisPromptName`, `analysisModelName`, `doneCriteria` | the analysis of a finished conversation and the human-readable completion definition |

The body is the directive. `AIConversationPurpose::getDirectiveBlock()` renders it under
`# Conversation purpose: <name>` and appends the blocked tools with the refusal text, rendered from the list. The
directive may carry marked sections like a skill body (reference [variants.md](variants.md)).

## Inheritance

`inheritsFrom: <purpose>` makes the file a thin override: every key the child does not set comes from the parent (the
plan, the rail, the knobs), the child's body replaces the parent's only when non-empty, the overlay applies after the
merge. One level deep. The class never inherits: each file names its own. A child reshapes the inherited plan in
code by overriding `AIConversationPurpose::buildSeedTaskPlanDefinition()`.

## Starting a purposed conversation

`AIConversationAgentService::startOrResumePurposedConversation()` (or `getOrStartPurposedConversation()` for the
conversation only) takes a drive scope, an `AgentSpace` and the name:

1. `AIConversationPurposeRunsService::findStartablePurposeForAgentSpace()`: registered, configured, `startableViaApi`,
   and for a CUSTOMER start every `requiredFeatureFlags` available to the space. An admin start is not gated. A space
   whose flags the host cannot answer is not gated either.
2. `AIConversationPurposeRunsService::resumeSingletonPurposedConversation()`: the newest still-active conversation of
   this purpose and space is returned (`AgentPurposedConversationStart::$resumedExisting`); a kickoff appendix is then
   NOT delivered and the caller injects it as a signal.
3. Otherwise a fresh conversation: `AIConversationPurpose::afterPurposedStart()`, the preferred model, the start
   settings (`AIConversationPurposeRunsService::buildStartSettingsForNewPurposedConversation()`, which drops the wire
   profile when the model cannot run it), the purpose stamp and the seed
   (`AIConversationPurposeRunsService::stampPurposeOnNewConversation()`), the preloads on the kickoff signal
   (`AIConversationPurposeRunsService::buildPurposeKickoffCapabilities()`), turn one.

What turn one runs with is declared by the purpose: the kickoff skills in the frontmatter key `preloadSkillNames`
(`AIConversationPurpose::$preloadSkillNames`, pinned unless `pinPreloadedSkills` is false) and the kickoff signal in
`kickoffSignalText` (`AIConversationPurpose::$kickoffSignalText`; without it the default text above). The signal is
written as the start signal, so the agent speaks first. The singleton is kept per space. A purposed conversation at account level (no space) keeps none; its caller drives
it with `AIConversationAgentService::driveConversation()` and the purpose name. Which route a host exposes and how a
start is scoped is the entry skill's (`ddd-module-agents-specialist`).

## One conversation per record: the subject

A purpose that works on one record at a time (one support ticket, one order) starts with a subject:
`AIConversationSubject::of($entityClass, $entityId)` (`src/Domain/AI/Entities/Conversations/Subjects/`), then
`AIConversationAgentService::startOrResumePurposedConversationOfSubject($scope, $agentSpace, $purposeName, $subject, …)`.

- The singleton is kept per space, purpose AND subject
  (`AIConversationsService::findNewestPurposedConversationOfAgentSpaceForSubject()`): the same record reopens its open
  conversation, another record gets a new one. Same validation and gating as the plain start.
- A start WITHOUT a subject never resumes a conversation of a record
  (`findNewestPurposedConversationOfAgentSpace()` leaves out every conversation with a subject row), so existing
  callers keep one conversation per space and purpose.
- The subject is stored in its own table `AIConversationSubjectBindings` (`AIConversationSubjectBinding`, one row per
  conversation, FK with ON DELETE CASCADE, index on space + purpose + class + id and on class + id; `purposeName` and
  `agentSpaceId` are nullable since free conversations carry a subject too), not as a column of
  `AIConversations`: every conversation read selects all columns of that table, so a new column there would break
  every read until the DDL ran. Without the table (`AIConversationSubjectBindingsService::isBindingTableAvailable()`,
  information_schema, a "missing" answer is asked again after 60 s) a subject is ignored and nothing fails.
- Two starts for the same record at the same moment run one after the other under the entry-point start lock
  (`lockEntryPointStart()` with the key `purpose:<md5>`).
- The caller checks that the account may read the record: `AIConversationSubject::findReadableSubjectEntity()` (the
  class must be an entity class with an `EntitiesService`; the record is read under the caller's read rights; 400 for a
  malformed class, 404 for an unknown or unreadable record).

- The new conversation is bound INSIDE the drive (`driveConversation(…, aIConversationSubject: $subject)`), after the
  purpose stamp and before the kickoff turn is dispatched, so the first model call reads the record segment below.

### What the model reads about the record

An application says what a record is through the port `AIConversationSubjectDescriber`
(`src/Domain/AI/Services/Conversations/Subjects/`, tag `ai.conversation_subject_describer`, autoconfigured):
`getSubjectEntityClasses(): string[]` and `describeSubject(AIConversationSubject, Entity): ?AIConversationSubjectDescription`.
The first describer that names the record's class (or a parent class) and answers wins. The answer
(`AIConversationSubjectDescription::of($description, $name, $link)`: one line in the application's words without
end-user text, the record's display name, an absolute URL or a path) is stored ONCE on the conversation's working
state when it is bound (`AgentWorkingState::$subjectDescription`, `AIConversationsService::persistSubjectDescriptionSlice()`)
and rendered into the system segment `CONVERSATION_SUBJECT` right after the purpose directive in every model call of
the conversation: byte-stable, so a reopened conversation still knows its record and the prompt cache holds. The name
is shown between the marks of untrusted content. Without a describer (or when it fails: logged as a warning) the
conversation is bound and has no record segment. A conversation bound before a describer existed gets none later.

```php
class SupportTicketSubjectDescriber extends Service implements AIConversationSubjectDescriber
{
    public function getSubjectEntityClasses(): array
    {
        return [SupportTicket::class];
    }

    public function describeSubject(AIConversationSubject $aIConversationSubject, Entity $subjectEntity): ?AIConversationSubjectDescription
    {
        /** @var SupportTicket $subjectEntity */
        return AIConversationSubjectDescription::of(
            'support ticket ' . $subjectEntity->id,
            $subjectEntity->title ?? null,
            '/support-tickets/' . $subjectEntity->id
        );
    }
}
```

### Free conversations about a record

A conversation WITHOUT a purpose can be bound to a record as well: the start names it
(`AIConversationStartWithMessagePostRequestDto::$subject`; PHP: `driveConversation(…, aIConversationSubject: $subject)`,
`startOrReopenConversationAtEntryPoint(…, aIConversationSubject: $subject)`). Any number of them may exist per record;
a free conversation is never the singleton of a purposed start. The binding has `purposeName` null (and `agentSpaceId`
null at account level).

- Which classes a client may name: `isSubjectEntityClassAllowedForConversationStart(string $subjectEntityClass)` of
  the controller hooks (default: none → 404; a malformed subject 400; an unreadable record 404). It is asked for the
  start and for the list below. The classes of purposed starts stay with `isSubjectEntityClassAllowedForPurposeStart()`.
- The routes of a record's conversations come with their own trait, `AgentConversationSubjectsTrait` (an update never
  mounts them): `GET …/listConversationsOfSubject?subjectEntityClass=…&subjectEntityId=…` (query options of the
  conversations list; body `buildConversationsOfSubjectListResponse()`; the caller's conversations of the record of
  every space, free and purposed, `AIConversationsService::findAllVisibleToCustomerOfSubject()`, plus `subject`: the
  record as the describer names it now, also when it has no conversation yet) and
  `GET …/{aiConversationId}/subject` (body `buildConversationSubjectResponse()`; answers `subject`:
  `aiConversationId`, `subjectEntityClass`, `subjectEntityId`, `purposeName`, `name`, `link`, or null) and
  `GET …/listSubjectsOfConversations` (the query options of `listAllConversations`; body
  `buildSubjectsOfConversationsListResponse()`; answers `subjects`, the set `AIConversationBoundSubjects`
  (`subjects.elements`), one element per bound conversation of that page,
  `AIConversationSubjectBindingsService::findBoundSubjectsOfConversations()`).
- Web: `startConversation({ …, subject })`, `listConversationsOfSubject(subject, options)`,
  `listSubjectsOfConversations(agentSpaceKey, options)` and `getConversationSubject(conversationId)` on
  `AgentsConversationClient` (the last three optional); contract types
  `ConversationSubject` (`PurposeSubject` is its alias), `BoundConversationSubject` and `ConversationsOfSubject`
  (the list's answer: `subject`, `conversations`).
- Schema: the table of a host on 0.6.9–0.7.1 needs `purposeName` and `agentSpaceId` nullable and the index on
  `subjectEntityClass, subjectEntityId` (the schema diff shows both). Until then a free start with a subject fails on
  the NOT NULL column, so apply the diff before a class is allowed; purposed starts are not affected.

### The route

`POST …/startPurpose` comes with its own trait, `AgentConversationPurposeStartTrait` (`startPurpose()`, body
`buildPurposeStartedResponse()`): a controller adds it on purpose; an update never mounts the route in a controller
that uses the other chat traits. Body: `purposeId`, `agentSpaceId` (optional), `subject` (optional;
`subjectEntityClass`, `subjectEntityId`), `prefill` (optional, ≤ 4,000 characters, the kickoff appendix of a new
conversation). Answer: `aiConversationId`, `resumedExistingConversation`, `prefillDelivered`. Scope CUSTOMER, so
`startableViaApi` and `requiredFeatureFlags` apply exactly as with the PHP starter. The space comes from
`findAgentSpaceOfPurposeStartRequest()` (default: the body's `agentSpaceId` under read rights, 400 without one); a host
with one personal space per account overrides it. The subject classes a client may name come from
`isSubjectEntityClassAllowedForPurposeStart()` (default: none, so a start with a subject answers 404 until the host
lists its classes; a start without a subject is not affected). A host that redeclares its routes to add a quota or an
envelope attribute redeclares this one too. Web: `AgentsConversationClient.startPurpose(request)` (optional on the
port) → `StartedPurposeConversation` (`conversationId`, `resumedExisting`, `prefillDelivered`); the host opens the
conversation by its id.

## The hooks of the purpose class

The base class implements every hook as "nothing". Override only what the purpose needs.

| Hook | When it runs | Use |
|---|---|---|
| `afterPurposedStart()` | fresh start and resume | stamp the host's own records; fail-soft |
| `buildTurnStartNotice()` | before the first model call of an owner turn (root only) | a note for the model, appended as a purpose-consequence signal |
| `buildWidgetAtTurnOpen()` | when a turn opens | a widget the platform writes, with its signal (`AIConversationPurposeTurnOpenWidget`) |
| `applyConsequencesOfPersistedTaskPlan()` | after every plan write | side effects of ticks; may return a note for the model |
| `reconcileTaskPlanAtSettledTurn()` | at the settled release point, before the close | change the plan in place; return true when changed |
| `shouldCloseConversationAfterSettledTurn()` | at the settled release point | true closes the conversation (no further owner input) |
| `completePurposeGoalAndConfirmItIsDurable()` | before that close | the side effect outside the conversation; the close happens only on true |
| `buildFinalAnswerAppendix()` | before the final answer is stored | lines the answer must carry; additive only |
| `doesToolResultEndThePurpose()` | after a completed tool | true forces one closing line |
| `getWidgetTypesThatEndThisPurpose()` | turn end | widget types that hand the turn to the owner |
| `backgroundWarmOperationsAfterStep()` | after a step | background warm-ups (`AIConversationPurposeWarmOperation`) |
| `getVariantDimensions()`, `resolveVariants()`, `variantDimensionsChangedByTool()` | turn start, after listed tools | variants (reference [variants.md](variants.md)) |
| `buildSupervisorPolicy()`, `buildDelegationResultVerdict()`, `buildDelegationResultTaskText()`, the run bookkeeping hooks (`recordStopOfRun()` and the others) | supervision, delegation, claimed turns | autonomy and loop skills |
| `getAnalysisItems()`, `getAnalysisPassScore()`, `getAnalysisFactToolNames()`, `buildAnalysisContext()`, `buildRunSegmentValues()` | analysis of a finished conversation | the host's quality measurement |

Write every hook fail-soft (catch inside the override): the package catches only around the turn-start notice and
the variant resolve; a throw from the plan-consequence hook or the close hooks (`reconcileTaskPlanAtSettledTurn()`,
`shouldCloseConversationAfterSettledTurn()`, `completePurposeGoalAndConfirmItIsDurable()`) reaches the loop. The class is built with `new` from the
purpose file (it has no repository); fetch `AIConversationPurposesService` from the container, never through an
entity shorthand.

## The seeded task plan

`seedTaskPlanItems` is a list; an item is a string (a leaf without a criterion) or a map with `text` (required),
`subtasks` (the same shape, nested) and the declarations of `TaskPlanItemDeclaration::FIELD_KINDS`, among them
`doneCriterion` (the key of a done-criterion class), `doneRequiresToolCall`, `doneRequiresWidget`,
`doneRequiresWidgetAnswered`, `skillNames` (preloaded when the item is the plan's next), `customerProgressStepKey`,
`minReasoningEffort` and `triggersSupervisorMilestoneCheckpoint`. `TaskPlanItems::fromPurposeSeedDefinition()` builds the
tree; duplicate texts are dropped. The seed is written at creation, not marked as written by turn one, so the agent
FINDS the plan. What each gate key requires before a tick is accepted, and how the plan tools work, belongs to the
loop skill (`ddd-module-agents-loop-specialist`). A singleton that takes a next run is re-seeded with
`AIConversationPurposeRunsService::reseedPurposeTaskPlan()`.

## Done criteria

A done criterion decides one seed key from the record. One class per key:

```php
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

class NotesFirstNoteKeptDoneCriterion extends TaskPlanDoneCriterion
{
    public const string KEY = 'notes.firstNoteKept';
    public const array CHANGED_BY_TOOLS = ['addDemoNote'];

    public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    { /* read the inputs, return a verdict or null */ }
}
```

The full class is [templates/DoneCriterion.php](../templates/DoneCriterion.php).

| Member of `TaskPlanDoneCriterion` | Meaning |
|---|---|
| `KEY` | the seed's `doneCriterion` value it decides, unique within the purpose |
| `LEGACY_KEYS` | keys it decided under before a rename; a stored plan's old key is renamed at the run start and keeps its gate |
| `CHANGED_BY_TOOLS` | tools whose COMPLETED call can change the verdict (the post-write re-sweep reads it) |
| `RESOLVES_ONCE_AN_OWNER_TURN_ENDS` | the verdict can change when an owner turn ends without a tool; re-read when the owner's next message opens a turn |
| `GATHERED_FACTS` | service-backed facts it reads through accessors of its inputs, lazily once per call |
| `resolve()` | reads its inputs only: no service call, no clock. Null = undecided, the leaf stays open |

Verdicts (`TaskPlanDoneCriterionVerdict`, through the protected helpers): `done()`, `doneOverridingPlatformBlock()`,
`notApplicable()`, `blockedByOwnerStatement()` and `stillOpen()` (the record says the leaf must stay open; a model tick
is refused with the reason).

The purpose registers its classes in `AIConversationPurpose::DONE_CRITERION_CLASSES`;
`TaskPlanDoneCriteria::forPurpose()` builds the registry, one instance per class built with `new` (no constructor
arguments), and throws on a class that is no criterion or on a key registered twice.
`AIConversationPurpose::TOOLS_CHANGING_NO_DONE_CRITERION` lists tools that change none (no re-read after them; any
other unlisted tool re-reads every open criterion leaf).

When a criterion is asked:

| Moment | Entry |
|---|---|
| creation: seeded leaves already true are pre-ticked | `AIConversationPurpose::preResolveSeedTaskPlanItems()` |
| after a completed tool: the open leaves its tool can change | `AIConversationTaskPlanRecordService::reResolveOpenSeededLeavesAfterWork()` |
| a model tick (`set_task_status`, `update_task_plan`) on a gated leaf | `AIConversationTaskPlanRecordService::applySetTaskStatusCall()` |
| the owner's next message, for `RESOLVES_ONCE_AN_OWNER_TURN_ENDS` | `AIConversationPurpose::getCriterionKeysResolvedOnceAnOwnerTurnEnds()` |

Rules that bite:

- A key without a registered class is no gate: the next run start drops it from the stored plan (trace
  `turn.taskPlan.doneCriteriaWithoutClassDropped`) and the item becomes a plain model item. A purpose that overrides
  `AIConversationPurpose::enforcesDoneCriterionOnTicks()` to true makes an undecided (null) or still-open verdict of a
  REGISTERED criterion refuse a `done` tick.
- A criterion that reads tool calls takes a call's tool name from `$message->toolCall->name`; the message's
  `toolName` is a virtual database column, filled only when a row is loaded, never during the run that made the call.
- A criterion that throws answers null for that call (`TaskPlanDoneCriteria::resolveKey()`).
- Inputs are domain-free (`TaskPlanDoneCriterionInputs`: the conversation, its messages, the live plan). A purpose
  whose criteria read the record of the space returns its own inputs subclass from
  `AIConversationPurpose::buildDoneCriterionInputs()` and loads the record there; a domain may add an abstract
  criterion base whose `resolve()` narrows the inputs type.
- A rule that closes several keys at once from one fact goes into `AIConversationPurpose::resolveVerdictWithPurposeRules()`,
  which every verdict passes.
- A stored key that no class decides any more is removed from the stored plan once per run
  (`AIConversationPurposeRunsService::dropDoneCriteriaWithoutClassFromStoredTaskPlan()`); rename with `LEGACY_KEYS`
  instead of deleting a class.

## The customer progress stepper

`customerProgressSteps` is an ordered list of maps with `key` and `customerLabel` (the source string of the client's
translation key). A seed item joins a step with `customerProgressStepKey`; a subtask without its own key inherits the
parent's. `AIConversation::buildCustomerProgressSteps()` builds `CustomerProgressSteps` (each step `done`, `active` or
`upcoming`; items the model adds are invisible; a leaf the platform pre-ticked at seed time is no progress) and the
read endpoint puts it on the conversation. The contract is presence: an empty set means no stepper. The plan's item
texts never reach the customer.

## Hidden purposes

`CUSTOMER_HIDDEN_PURPOSE_NAMES` (read through `AIConversationPurpose::getCustomerHiddenPurposeNames()`) lists purposes
whose conversations are the platform's own work: never in a customer's history, search or recall, fully visible to
an admin. A conversation is hidden when its own or its root's purpose is listed. A host redeclares the list on its
registry class. A host's customer read path calls `AIConversationsService::findVisibleToCustomer()` and a list applies
`DBAIConversations::restrictToCustomerVisibleConversations()`; the read rights stay untouched. Three more host lists:
`PURPOSE_NAMES_CLOSED_WITHOUT_AN_OWNER_TURN`, `PURPOSE_NAMES_HELD_AS_A_PHONE_CALL` and
`PURPOSE_NAMES_LEFT_OUT_OF_THE_SEARCH_ACROSS_CONVERSATIONS` — purposes visible to the owner whose conversations the
search across conversations still never answers from (the package lists `Schedule.Run`; the hidden purposes are left
out anyway; scheduled runs by initiator type: `AIConversation::INITIATOR_TYPES_LEFT_OUT_OF_THE_SEARCH_ACROSS_CONVERSATIONS`).

**Purposes closed without an owner turn.** A purpose is closed at the settled release point of a turn. When the turn
that reaches the goal is the owner's last one and the close was deferred (a failed `completePurposeGoalAndConfirmItIsDurable()`,
open offers not yet reconciled), no later turn re-evaluates it. A host lists such purposes in
`PURPOSE_NAMES_CLOSED_WITHOUT_AN_OWNER_TURN` on its registry class (the package lists none; read through
`AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn()`). Its reap pass then asks, per listed purpose,
`AIConversationsService::findQuietIdlePurposedConversationIds()` for the IDLE root conversations quiet for a window
(`AIConversationStuckRunsReadService::PURPOSE_SETTLE_QUIET_SECONDS` is the window the stuck-runs read assumes) and
runs `AIConversationAgentService::settlePurposeConversationIfGoalReached()` on each: it delivers a waiting supervisor
intervention first, else re-runs reconcile, completion and close. Idempotent. The reap pass is a console command of the
host (`ddd-module-agents-specialist`, host duties).

## A run nobody reads

Rules for a purposed run whose result code reads and no person does (a hidden purpose, an unattended run):

- **It is still a conversation.** The same loop and the same workers drive it. Only data makes it different: the
  purpose (`startableViaApi: false`, `blockedToolNames`, `loadsEarlierSessionsItself`), the initiator type (a run whose
  initiator type is neither empty nor `PHONE_CALL` is not user-present: `AIConversation::isUserPresentRun()` is false,
  so it is never supervised, and `AIConversationOrigin::getReasonItIsNotTheOwnersConversation()` tells a host it holds
  no owner's words), and the run settings its starter stamps.
- **The record is the entity, the run is disposable.** The run writes its result through one tool call into a record
  of its own; the code that needs the result reads that record, never the run's transcript.
- **A failed run is noticed by the next request, not by the loop.** A turn that fails (an error, a provider or
  transport failure) calls no purpose hook; only the loop's own stops (step budget, monthly budget, a scope no
  longer eligible, a terminal loop stop) reach `AIConversationPurpose::recordStopOfRun()`. A host that
  starts such runs and holds a claim or a record per run releases it itself: its next request reads the run's state
  and treats a terminal one as ended.
- **IDLE is not an end.** A conversation is IDLE between its chunks and while its sub-agents run. A run has ended
  when its run state is terminal (`AIConversationRunState::isTerminal()`: completed, failed, cancelled), and is still
  working while `AIConversation::isRunInProgress()` holds; a purpose that never closes its conversation ends when its
  record is written. Bound every wait by a maximum age.
- **Its tools authorise through the root conversation.** A delegation child carries no purpose, so a tool that
  belongs to one kind of run finds its run with `AIConversationsService::findRootConversationForToolCall()` (null on
  the MCP route, where no conversation exists) and checks the root's purpose, not the calling conversation's.

## Reading purposed runs

`AIConversationPurposedRunsReadService::findPurposedRuns()` pages the root runs of purposes in a window with their stall
reason (`AIConversationPurposedRunStallReason`), plan progress and open leaves; `reviewPurposeCriteria()` counts per
criterion key how often it stays open across runs. The filter is `AIConversationPurposedRunsFilter::fromRequest()`.
Authorization is the caller's: every account's rows are read. No route ships; a host exposes it to admins only.

## The package's purposes and the purpose of a scheduled run

The package ships three, all `startableViaApi: false`: `Schedule.Run`, `Supervisor.Checkpoint`,
`Background.MechanicalOperation`; the last two are hidden from customers. `Schedule.Run` shows the shape of an
unattended run's purpose: the seed message is the owner's mission only; the directive states the run's role (execute,
never administer a schedule; nobody is present); the delivery contract is chosen by the variant dimension
`reportChannel` (`## [reportChannel=EMAIL]` and `## [reportChannel=CHAT]` sections, resolved by `ScheduleRunPurpose`);
every tool that declares `MCPToolSendsMessageToOwner` is blocked (`ScheduleRunPurpose::getToolNamesBlockedByDeclaration()`),
so the report has one delivery path; `Agent.TaskRunReports` is
preloaded; `ScheduleRunPurpose::shouldCloseConversationAfterSettledTurn()` closes the run once the report reached its
channel. A schedule may name another purpose for its runs (a pinned model, a seeded plan): its class must be
`ScheduleRunPurpose` or extend it, usually with `inheritsFrom: Schedule.Run`. How a schedule fires, chooses that
purpose and delivers belongs to `ddd-module-agents-autonomy-specialist` (schedules reference, "A purpose of the
schedule's own").
