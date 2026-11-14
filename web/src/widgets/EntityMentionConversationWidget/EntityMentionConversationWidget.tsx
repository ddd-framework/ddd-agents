import { useMemo } from "react";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { EntityMentionCard } from "./EntityMentionCard/EntityMentionCard";
import { parseEntityMentionPayload } from "./shared/entity-mention";
import styles from "./EntityMentionConversationWidget.module.scss";

/**
 * The registry `component` of `EntityMention`: the read-only echo of the entity the owner asked
 * about, shown above the prose of the owner's OWN message (`ConversationMessageUserWidgets`) — the entity's card (type icon,
 * "Asking about", name) in place of the composer's chip. The owner put it there with the detail panel's "Ask about this" button
 * (`EntityMentionAskButton`); the agent never writes it. A payload that names no entity renders nothing and reports
 * `failed INVALID_PROP`.
 */
export const EntityMentionConversationWidget = (props: PayloadConversationWidgetProps) => {
  const { payload } = props;
  const mention = useMemo(() => parseEntityMentionPayload(payload), [payload]);
  useReportWidgetRenderStatus(
    mention === null ? "failed" : "mounted",
    mention === null ? "INVALID_PROP" : undefined,
    mention === null ? "the EntityMention names no entity (entityType + entityId)" : undefined,
  );
  if (mention === null) return null;
  return (
    <div className={styles.row} data-testid="entity-mention-message">
      <EntityMentionCard mention={mention} />
    </div>
  );
};
