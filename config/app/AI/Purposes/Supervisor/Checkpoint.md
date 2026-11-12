---
purposeClass: DDD\Domain\AI\Entities\Conversations\Purposes\SupervisorCheckpointPurpose
description: System-spawned tandem oversight conversation (the supervisor) that runs 1:1 alongside a supervised conversation. Each checkpoint is a turn; never startable via the API.
startableViaApi: false
usesSynthesizedOwnerText: true
---

RESERVED SYSTEM PURPOSE — this conversation is the supervisor's own tandem thread. The agent loop opens it
1:1 for a supervised conversation and appends one TURN per checkpoint: the seed USER message is the checkpoint
briefing (the delta-context since the previous checkpoint), the ASSISTANT message is the verdict JSON. The
supervisor's operating mandate lives in the supervisor prompt (`AI.Prompts.AI.Supervisor.Checkpoint`), NOT here. This
directive is never read by a turn model — the conversation has no owner, no kickoff greeting, and is driven only by
the supervisor worker.
