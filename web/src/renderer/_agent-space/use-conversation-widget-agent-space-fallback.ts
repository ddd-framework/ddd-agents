import { SectionHydrationResult } from "../widget-hydration";
import { useResolvedConversationWidgetAgentSpaceKey } from "./use-resolved-conversation-widget-agent-space-key";

/**
 * The empty fallback of a widget of another agent space: such a widget must not merge what the app holds for the
 * ACTIVE agent space into its own data.
 */
const ANOTHER_AGENT_SPACE_EMPTY_FALLBACK = { data: null, isLoading: false, error: null } as const;

/**
 * Resolves the widget's agent space and picks the fallback every snapshot widget needs with it.
 *
 * - Active agent space → the caller's live `activeFallback`, so `mergeSection` keeps merging the app's fresher data.
 * - Another agent space → a static empty result, so the widget's own data passes through `mergeSection` unmixed.
 *
 * Pass the result of the active-fallback hook in directly:
 *   useConversationWidgetAgentSpaceFallback(agentSpaceKeyAttribute, useSomeActiveHydration())
 * The hook order is the same on every render.
 */
export const useConversationWidgetAgentSpaceFallback = <T>(
  agentSpaceKeyAttribute: string | number | undefined,
  activeFallback: SectionHydrationResult<T>,
): {
  anotherAgentSpaceKey: number | undefined;
  isAnotherAgentSpace: boolean;
  fallback: SectionHydrationResult<T>;
} => {
  const { anotherAgentSpaceKey, isAnotherAgentSpace } = useResolvedConversationWidgetAgentSpaceKey(agentSpaceKeyAttribute);
  return {
    anotherAgentSpaceKey,
    isAnotherAgentSpace,
    fallback: isAnotherAgentSpace ? (ANOTHER_AGENT_SPACE_EMPTY_FALLBACK as SectionHydrationResult<T>) : activeFallback,
  };
};
