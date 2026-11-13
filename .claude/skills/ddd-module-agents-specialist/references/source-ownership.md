# Source ownership: which skill explains which file

**Reader:** package maintainer

Contents: why a manifest · the format · first match wins · the nine skill ids · how the check reads it · the
maintenance rule · changing the manifest.

## Why a manifest

The package's developer knowledge is cut like its code: nine skills, each explaining one part. Several folders mix
parts (the agent entity folder, the agent service folder, the top-level conversation services), so a folder cannot be
the unit. `source-ownership.tsv`, next to this file, gives **every file under `src/` and `config/` exactly one owning
skill**. It is the single input of the ownership check, of the framework's skill gate for this module and of a host's
rules that ask for the owning skill before an edit of a subclass.

## The format

One rule per line, two fields separated by one TAB: a glob relative to the package root, then a skill id. Empty lines
and lines starting with `#` are comments. The comment lines name each group with its skill and scope.

```text
src/Domain/AI/**/AgentSpaces/**	entry
src/Domain/AI/Entities/Conversations/Agent/AgentApplicationWording.php	entry
config/app/AI/agent*.php	entry
```

The glob syntax is kept small, so that a PHP test can translate it the same way:

| Token | Matches |
|---|---|
| `**/` | zero or more whole path segments |
| `**` at the end of a pattern | anything, `/` included |
| `*` | any characters except `/` |
| every other character | itself |

A pattern is anchored at both ends: it matches the whole path, never a part of it.

## First match wins

The rules are read top to bottom; the first rule whose pattern matches a file owns it. Every named exception therefore
stands **above** the folder pattern it pierces. The groups are ordered so that the skills whose classes sit inside
mixed folders come first (observability, decisions and guards, autonomy, widgets, skills and purposes, context,
tools, entry) and the loop, the default owner of the mixed loop folders, comes last.

Example: `config/app/AI/agentToolCallChildProcess.php` matches the tools rule
`config/app/AI/agentToolCallChildProcess.php` and the entry rule `config/app/AI/agent*.php`; the tools rule stands
higher, so the tools skill owns it.

## The nine skill ids

| Id | Skill |
|---|---|
| "entry" | `ddd-module-agents-specialist` |
| "loop" | `ddd-module-agents-loop-specialist` |
| "context" | `ddd-module-agents-context-specialist` |
| "skills-and-purposes" | `ddd-module-agents-skills-and-purposes-specialist` |
| "tools" | `ddd-module-agents-tools-specialist` |
| "widgets" | `ddd-module-agents-widgets-specialist` |
| "autonomy" | `ddd-module-agents-autonomy-specialist` |
| "decisions-and-guards" | `ddd-module-agents-decisions-and-guards-specialist` |
| "observability" | `ddd-module-agents-observability-specialist` |

An unknown id in the second field is a format error.

## How the check reads it

The ownership check is the package's contract test "DeveloperSkillSourceOwnershipTest" (it ships with the skills). It
translates the globs the same way, walks every file under `src/` and `config/` of the package, matches each against
the rules in order and fails when:

- a rule names an unknown skill id or is malformed (not exactly two TAB-separated fields);
- a file matches no rule;
- a rule matches no file (a dead rule is a renamed or removed file whose skill text may be stale).

Run it with the package suite ([package-suite.md](package-suite.md)).

## The maintenance rule

Whoever changes a package class in a way that changes a statement of a skill updates the owning skill, found through
this manifest, **in the same release**. The contract tests enforce the part a machine can see: a backticked name in a
skill text that no longer exists, a file without an owner, a rule without a file, a format error. What a machine
cannot see (a changed default, a new trap, a changed contract of a host duty) is the author's duty.

## Changing the manifest

- **A new file** in a folder another rule already covers needs nothing; check that the covering rule names the skill
  that should explain it.
- **A new file that belongs to another skill than its folder's** gets a named rule above the folder rule.
- **A moved or renamed file** whose old path had a named rule: change the rule, or the check reports a dead rule.
- **A new folder** gets a folder rule in its owner's group.
- Run the check after every change; it must report no unowned file and no dead rule.
