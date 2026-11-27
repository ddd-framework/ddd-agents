<?php

declare(strict_types=1);

/**
 * Raw sink calls of this package that are fine, each with its reason (Core skill ddd-untrusted-input-specialist).
 * Checked by tests/UntrustedSinkScanTest.php: a new raw call fails until it goes through a helper of
 * DDD\Infrastructure\Untrusted or is listed here; an entry without a matching call fails too.
 */
return [
    ['file' => 'src/Domain/AI/Services/Conversations/AgentToolCallForkJoinService.php', 'sink' => 'process', 'reason' => 'our own console script for a forked tool call, argument array; the call arguments travel in a file of our own'],
    ['file' => 'src/Domain/AI/Services/Connections/Adapters/Slack/SlackAgentConnectionAdapter.php', 'sink' => 'http-client', 'reason' => 'the Slack Web API on the fixed host https://slack.com/api/ (API_BASE_URL) with fixed method names; no URL from outside'],
    ['file' => 'src/Domain/AI/Services/Eval/Probes/AgentReasoningReplayProbeTransportService.php', 'sink' => 'http-client', 'reason' => 'the reasoning-replay probe posts its own composed bodies to the three fixed OpenRouter endpoints (an allow-list in the method); no URL from outside'],
    ['file' => 'src/Domain/AI/Services/Skills/AISkillsService.php', 'sink' => 'yaml-parse', 'reason' => 'frontmatter of the skill files shipped with the code, no object flags'],
    ['file' => 'src/Domain/AI/Services/Voice/Live/OpenAILiveVoiceProviderAdapter.php', 'sink' => 'http-client', 'reason' => 'the two REST calls of a voice session (create, hang up) go to the provider host of the committed config (AI.agentVoice.provider.<name>.apiHost) on fixed paths, the session id URL-encoded; no URL from outside'],
    ['file' => 'src/Symfony/Commands/Agents/AgentEvalGuardCommand.php', 'sink' => 'process', 'reason' => 'the command re-runs itself per case, argument array'],
    ['file' => 'src/Symfony/Commands/Agents/AgentEvalSuiteCommand.php', 'sink' => 'process', 'reason' => 'the command re-runs itself per case, argument array'],
    ['file' => 'src/Symfony/Commands/Agents/AgentEvalToolsCommand.php', 'sink' => 'process', 'reason' => 'the command re-runs itself per case, argument array'],
    ['file' => 'src/Symfony/Commands/Agents/Chat/ConsoleLineEditor.php', 'sink' => 'shell', 'reason' => 'fixed stty/tput commands of the developer console; the one variable part is the saved stty state, escapeshellarg-quoted'],
    ['file' => 'src/Symfony/Commands/Agents/Chat/CopySlashCommand.php', 'sink' => 'process', 'reason' => 'pbcopy of the developer console, argument array, the text on stdin'],
    ['file' => 'src/Symfony/Commands/Agents/MCPClearCacheCommand.php', 'sink' => 'process', 'reason' => 'the command rebuilds the container in a child (this process\'s PHP binary and console script, the container cache clear command), argument array'],
];
