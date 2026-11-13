#!/usr/bin/env sh
# The agent's cron list, registered as rows of the framework's cron table (DDD\Domain\Common\Entities\Crons\Cron).
# Placeholders: {{CONSOLE}} sample: php bin/console
#               {{COMMAND_PREFIX}} sample: agent
# The command names are the samples of the templates beside this file; use the names the host gave its commands.
# `app:crons:upsert` validates the expression and that the command exists, `--dryRun` writes nothing. The rows run
# through the framework's ticker, which the host starts every minute from its system scheduler:
#   * * * * * {{CONSOLE}} app:crons:execute
# The ticker gives every command at most Crons::TIMEOUT_PER_CRON_IN_SECONDS (360 s). The schedule, owed-work and
# finalize-dispatch passes are bounded by their batch options; the reaper's selectors and the finalize mark are not,
# which stays cheap only while few runs are stuck; the purge stops after its own batch cap.
set -e

{{CONSOLE}} app:crons:upsert --name=agent-schedules-fire \
  --description="Fire the due agent schedules: claim each occurrence once and spawn its run" \
  --schedule="* * * * *" --command="{{COMMAND_PREFIX}}:schedules:fire-due"

{{CONSOLE}} app:crons:upsert --name=agent-schedules-state-sweep \
  --description="Close, pause or re-arm agent schedules whose space or account changed its state" \
  --schedule="17 * * * *" --command="{{COMMAND_PREFIX}}:schedules:sweep-state"

{{CONSOLE}} app:crons:upsert --name=agent-runs-recover-owed-work \
  --description="Deliver owed agent run reports and resume agent turns that are still owed" \
  --schedule="*/5 * * * *" --command="{{COMMAND_PREFIX}}:runs:recover-owed-work"

{{CONSOLE}} app:crons:upsert --name=agent-runs-reap \
  --description="Wake or expire agent runs whose worker died, time out stale delegation batches" \
  --schedule="*/5 * * * *" --command="{{COMMAND_PREFIX}}:runs:reap"

{{CONSOLE}} app:crons:upsert --name=agent-conversations-finalize-idle \
  --description="Mark idle agent conversations finalized and dispatch their completion compaction" \
  --schedule="*/5 * * * *" --command="{{COMMAND_PREFIX}}:conversations:finalize-idle"

{{CONSOLE}} app:crons:upsert --name=agent-events-purge \
  --description="Purge agent trace and turn events past their retention, then the unreferenced snapshots" \
  --schedule="43 3 * * *" --command="{{COMMAND_PREFIX}}:events:purge"
