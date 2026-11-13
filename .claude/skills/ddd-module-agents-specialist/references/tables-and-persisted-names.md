# Tables and persisted names

**Reader:** host developer and package maintainer

Contents: the database entities · how a table is named · keeping a host's own table names · the schema · names that
leave the process.

## The database entities

Thirteen entities of the package have a database repository; every other entity is a value object, a config file or
an Argus load.

| Entity (set) | What a row is | Skill that explains it |
|---|---|---|
| `DDD\Domain\AI\Entities\AgentSpaces\AgentSpace` (`AgentSpaces`) | what the agent works for | this skill, [agent-space.md](agent-space.md) |
| `AIConversation` (`AIConversations`) | one conversation, its run state and working state | `ddd-module-agents-loop-specialist` |
| `AIConversationMessage` (`AIConversationMessages`) | one message, tool call, tool result or signal | `ddd-module-agents-loop-specialist` |
| `AIConversationMessageEmbedding` (`AIConversationMessageEmbeddings`) | the vector of one message (VECTOR column) | `ddd-module-agents-context-specialist` |
| `AIConversationCompactionNode` (`AIConversationCompactionNodes`) | one node of the compaction tree | `ddd-module-agents-context-specialist` |
| `AIConversationToolCallBatch` (`AIConversationToolCallBatches`) | the tool calls of one model response | `ddd-module-agents-tools-specialist` |
| `AIConversationDelegationBatch` (`AIConversationDelegationBatches`) | one fan-out to sub-agents and its deadline | `ddd-module-agents-autonomy-specialist` |
| `AIConversationSchedule` (`AIConversationSchedules`) | one schedule of unattended runs | `ddd-module-agents-autonomy-specialist` |
| `AgentMandate` (`AgentMandates`) | one standing permission | `ddd-module-agents-autonomy-specialist` |
| `AIConversationTaskPushNotificationConfig` (`AIConversationTaskPushNotificationConfigs`) | the push target of an external agent | `ddd-module-agents-widgets-specialist` |
| `AIConversationTraceEvent` (`AIConversationTraceEvents`) | one trace event of a run | `ddd-module-agents-observability-specialist` |
| `AIConversationTurnEvent` (`AIConversationTurnEvents`), `AIConversationTurnEventSnapshot` (`AIConversationTurnEventSnapshots`) | the timed events of one turn | `ddd-module-agents-observability-specialist` |

Conversations, schedules and mandates carry `accountId` and `agentSpaceId`. The read rights of the conversation,
message, schedule, batch, event and embedding repositories run through `DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService`; the space and the
mandate repositories keep their own rule (the row's own account, or an admin with access to all resources).

## How a table is named

No entity of the package carries a fixed table name, and the package ships its Doctrine models without a table
prefix. A table is `DATABASE_TABLE_PREFIX` plus the name of the entity set (`AIConversationMessages`; with the prefix
"Entity": "EntityAIConversationMessages").

Module code reads table names from the module's model classes (`DBAIConversationScheduleModel::getTableName()` in a
raw statement). A same-path model in the host therefore cannot change a name; only the regeneration of the models in
the host's vendor copy does.

## Keeping a host's own table names

A host that keeps another name for a package table lists it in its "config/app/Base/Repo/database.php":

```php
return [
    'tableNames' => [
        \DDD\Domain\AI\Entities\Conversations\AIConversation::class => 'HostConversations',
    ],
];
```

The name is taken verbatim (no prefix) and wins over a `DatabaseTableName` attribute; the entry of the package class
also covers the host's same-path subclass (`EntityModelGeneratorService::TABLE_NAMES_CONFIG_KEY`). The package ships
no such file.

A host with a prefix or with `tableNames` entries regenerates the models in vendor with
`app:generate-doctrine-models-for-entities`. No package declares a Composer script: the host adds the command to
`post-install-cmd` and `post-update-cmd` of its own `composer.json` (or runs it after every install and update) and
**never runs Composer with `--no-scripts`**. Without the regeneration the vendor models keep the shipped names, and
every module statement that reads a table name from a model hits a missing table. After a change of a name:
regenerate, then clear the cache.

## The schema

`app:entity:show-sql` prints the DDL generated from the entity attributes (MariaDB 11.8 or newer for the VECTOR
columns, or PostgreSQL). The generator orders tables by entity, so a foreign key may name a table created further down:
apply with the foreign key checks off. There is no command that creates or migrates the schema; schema changes of a
release reach a host as a difference between the generated DDL and its database.

A host that derives `AgentSpace` with a required column of its own creates the space rows itself; the package never
creates one ([agent-space.md](agent-space.md)).

## Names that leave the process

| Group | Where | Rule |
|---|---|---|
| `objectType` values in stored JSON | every value object stored in a JSON column (the conversation's working state, a message's tool call, a widget) carries its class name | never move or rename such a class without a `Common.objectTypeMigrations` entry (old name to new) |
| Messenger message classes | the nine classes of `AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS`, in a broker queue during a deploy | a rename needs drained queues or a `class_alias` of the old name in the host |
| Stored kinds and wire names | a state note kind (`agentSpaceContextStateKind` of the wording), signal sources, widget wire names | a host that changes one no longer reads the rows stored under the earlier value |

Package maintainer: a rename of a persisted class ships with its migration entry in the same release, and the
skill that owns the class names it ([source-ownership.md](source-ownership.md)).
