#!/usr/bin/env sh
# The agent's cron list, registered as rows of the framework's cron table (DDD\Domain\Common\Entities\Crons\Cron).
# Placeholders: {{CONSOLE}} sample: php bin/console
#               {{COMMAND_PREFIX}} sample: agent
# Commands named app:... are the package's own; their names are fixed. Commands named {{COMMAND_PREFIX}}:... are the
# samples of the templates beside this file; use the names the host gave its commands.
# `app:crons:upsert` validates the expression and that the command exists, `--dryRun` writes nothing. The rows run
# through the framework's ticker, which the host starts every minute from its system scheduler:
#   * * * * * {{CONSOLE}} app:crons:execute
# The ticker gives every command at most Crons::TIMEOUT_PER_CRON_IN_SECONDS (360 s). The schedule, owed-work and
# finalize-dispatch passes are bounded by their batch options; the reaper's selectors and the finalize mark are not,
# which stays cheap only while few runs are stuck; the purges stop after their own batch cap. The optional
# maintenance rows at the end pass --maxSeconds below the ticker's limit (their default, 1500 s, is above it).
# Never add --ignore-budget to the fire row: every run it spawns would carry the budget bypass.
set -e

{{CONSOLE}} app:crons:upsert --name=agent-schedules-fire \
  --description="Fire the due agent schedules: claim each occurrence once and spawn its run" \
  --schedule="* * * * *" --command="app:ai-conversation:fire-due-schedules"

{{CONSOLE}} app:crons:upsert --name=agent-schedules-state-sweep \
  --description="Close, pause or re-arm agent schedules whose space or account changed its state" \
  --schedule="17 * * * *" --command="{{COMMAND_PREFIX}}:schedules:sweep-state"

{{CONSOLE}} app:crons:upsert --name=agent-runs-recover-owed-work \
  --description="Deliver owed agent run reports and resume agent turns that are still owed" \
  --schedule="*/5 * * * *" --command="{{COMMAND_PREFIX}}:runs:recover-owed-work"

{{CONSOLE}} app:crons:upsert --name=agent-runs-reap \
  --description="Wake or expire agent runs whose worker died, time out stale delegation batches" \
  --schedule="*/5 * * * *" --command="{{COMMAND_PREFIX}}:runs:reap"

{{CONSOLE}} app:crons:upsert --name=agent-conversations-mark-finalized \
  --description="Mark agent conversations idle past the window as finalized" \
  --schedule="*/5 * * * *" --command="app:ai-conversation:mark-finalized"

{{CONSOLE}} app:crons:upsert --name=agent-conversations-compact-finalized \
  --description="Dispatch the completion compaction of finalized agent conversations" \
  --schedule="*/5 * * * *" --command="app:ai-conversation:compact-finalized"

{{CONSOLE}} app:crons:upsert --name=agent-trace-events-purge \
  --description="Purge agent trace events past their retention" \
  --schedule="43 3 * * *" --command="app:ai-agent:trace-events-purge"

{{CONSOLE}} app:crons:upsert --name=agent-turn-events-purge \
  --description="Purge agent turn events past their retention, then the unreferenced snapshots" \
  --schedule="53 3 * * *" --command="app:ai-agent:turn-events-purge"

# Optional maintenance: remove the leading "# " of the rows the host wants.
#
# {{CONSOLE}} app:crons:upsert --name=agent-request-snapshots-strip \
#   --description="Remove the debug payloads from old agent request snapshots" \
#   --schedule="13 2 * * *" --command="app:ai-agent:request-snapshot-debug-payloads-strip --maxSeconds=300"
#
# Only once every process that reads loaded-capability rows reads the chain format:
# {{CONSOLE}} app:crons:upsert --name=agent-loaded-capabilities-compact \
#   --description="Rewrite legacy loaded-capability rows into checkpoints and change records" \
#   --schedule="23 2 * * *" --command="app:ai-agent:loaded-capabilities-compact --maxSeconds=300"
#
# {{CONSOLE}} app:crons:upsert --name=agent-embeddings-repair \
#   --description="Re-embed agent embeddings that hold the zero vector" \
#   --schedule="33 2 * * *" --command="app:ai-conversation:repair-zero-embeddings --apply --newestRowsOnly=50000 --limit=200 --maxSeconds=200"
