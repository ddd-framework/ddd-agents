/**
 * `@mgamadeus/ddd-agents/ui` — the client side of `Agent.UI`: the runner of the client tool requests, the four
 * adapter contracts an application implements (`ScreenTargetSource`, `ScreenNavigator`, `ScreenReader`,
 * `ScreenHighlighter`, optionally `ScreenPictureCapturer`, `AgentWindowAdapter`, `ScreenPathGuide`, `ScreenActor` (the
 * typed actions on declared elements, `DomScreenActor`) and handlers of further request types), the Markdown writer and reference registry, DOM defaults (reader, highlighter, target
 * source from `data-agent-anchor` marks, picture capturer, path guide), the agent-window adapter, the realtime arrival
 * adapter and dev helper, the account-level realtime poll (a client that shows no conversation), the UI state the client reports, and the hook.
 */
export * from "../ui/client-tools";
export * from "../ui/agent-ui-state";
export { ClientToolRequestRunner, MAX_SCREEN_CHARS } from "../ui/ClientToolRequestRunner";
export { AgentUIClient } from "../ui/AgentUIClient";
export { useAgentUIClient } from "../ui/useAgentUIClient";
export * from "../ui/screen-markdown";
export * from "../ui/DomScreenReader";
export * from "../ui/DomScreenHighlighter";
export * from "../ui/DomScreenActor";
export * from "../ui/DomScreenTargetSource";
export * from "../ui/DomScreenPathGuide";
export * from "../ui/screen-picture";
export * from "../ui/agent-window";
export * from "../ui/realtime-arrival";
export * from "../ui/account-realtime";
