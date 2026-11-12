---
purposeClass: DDD\Domain\AI\Entities\Conversations\Purposes\BackgroundMechanicalOperationPurpose
description: System-spawned background delegation child that executes ONE named operation without any model call — CMS detection, cache-warming tool reads. Never startable via the API.
startableViaApi: false
---

RESERVED SYSTEM PURPOSE — no model ever reads this directive. A conversation with this purpose is a
delegation CHILD the agent loop spawns as one BACKGROUND sub-agent at deterministic post-write sites.
Its seed USER message carries a label line plus one JSON payload line
(`{"operation": "detectAndSaveCMS"}` or `{"operation": "executeTool", "toolName": "...", "toolArguments": {...}}`
— built and parsed by `BackgroundMechanicalOperationPurpose`). The drive path executes the operation directly
(zero model calls), writes the result as the child's answer, and ends the child's run;
the parent is never parked or auto-driven — the run end reaches it as one `SUB_AGENT_RUN_ENDED_WITHOUT_RESUME`
notification on its next genuine turn.
