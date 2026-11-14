import React from "react";
import { Tooltip } from "../../provider/components";
import {
  buildUnnamedAgentSpaceLabel,
  buildWidgetOfAnotherAgentSpaceExplanation,
  translateText,
} from "../../provider/environment";
import { useWidgetHydration } from "../widget-hydration";
import {
  AGENT_SPACE_NAME_WIDGET_TYPE,
  AgentSpaceNameData,
  AgentSpaceNameHydrationInput,
} from "./agent-space-name.hydrator";
import styles from "./ConversationWidgetAgentSpaceHeader.module.scss";

export interface ConversationWidgetAgentSpaceHeaderProps {
  agentSpaceKey: number;
  /**
   * Render the chip in the normal flow instead of floating it over the top-right corner of the enclosing frame — for a
   * surface whose corner is taken (the open arrow of a canvas handle, a canvas heading). The parent places it.
   */
  inFlow?: boolean;
}

/**
 * The chip above a widget whose block names another agent space than the one the owner works in. The name is read
 * through the widget hydration, so several chips of one agent space share one read and the entry is cleared with the
 * other widget data when the conversation changes. The app words what kind of thing an agent space is
 * (`AgentsEnvironment.buildUnnamedAgentSpaceLabel`, `buildWidgetOfAnotherAgentSpaceExplanation`).
 */
export const ConversationWidgetAgentSpaceHeader = (props: ConversationWidgetAgentSpaceHeaderProps) => {
  const { agentSpaceKey, inFlow = false } = props;
  const { data } = useWidgetHydration<AgentSpaceNameData, AgentSpaceNameHydrationInput>(AGENT_SPACE_NAME_WIDGET_TYPE, {
    agentSpaceKey,
  });

  // Each text carries the name of the agent space, so it goes through the translation with a replacement instead of a
  // template string — a template string is a new key per agent space.
  const label = data?.name ?? buildUnnamedAgentSpaceLabel(agentSpaceKey);
  const explanation = buildWidgetOfAnotherAgentSpaceExplanation(label);
  const noteLabel = translateText("Showing data for %label%", { label });

  return (
    <div className={inFlow ? styles.inFlowAnchor : styles.anchor}>
      <Tooltip text={explanation} placement="top" dark wrapsChild>
        <span className={styles.root} role="note" aria-label={noteLabel} tabIndex={0}>
          <span className={styles.name}>{label}</span>
        </span>
      </Tooltip>
    </div>
  );
};
