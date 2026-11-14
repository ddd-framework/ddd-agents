import { Text } from "../../../provider/components";
import { ConversationWidgetProvider } from "../../../renderer/ConversationWidgetContext";
import { ConversationMessageInputComposerWidgets } from "../../../chat/ConversationMessageInput/ConversationMessageInputComposerWidgets/ConversationMessageInputComposerWidgets";
import { EntityMentionAskButton } from "../EntityMentionAskButton/EntityMentionAskButton";
import styles from "./EntityMentionAskFlowStory.module.scss";

const ENTITIES = [
  { entityType: "competitor", entityId: "8812", label: "Altes Gasthaus Leve", agentSpaceKey: 1 },
  { entityType: "competitor", entityId: "9001", label: "Kleiner Kiepenkerl", agentSpaceKey: 1 },
];

/**
 * The "Ask about this" flow for the story and the browser check: a stand-in for the detail panel's
 * footer (the `entityDetail` shell is the canvas shell's, the bodies come from the entity types) with the real button, and the real
 * composer strip of the chat input, so a click shows the chip where the owner types.
 */
export const EntityMentionAskFlowStory = (props: { maxWidth?: number }) => (
  <ConversationWidgetProvider callbacks={{}}>
    <div className={styles.page} style={{ maxWidth: props.maxWidth ?? 720 }}>
      {ENTITIES.map((entity) => (
        <div key={entity.entityId} className={styles.panel}>
          <Text role="intro" weight="bold" translate={false}>
            {entity.label}
          </Text>
          <Text role="help" color="var(--agents-fg-light)" translate={false}>
            {"detail panel body (domain lane)"}
          </Text>
          <div className={styles.footer}>
            <EntityMentionAskButton {...entity} testId={`entity-mention-ask-${entity.entityId}`} />
          </div>
        </div>
      ))}
      <div className={styles.composer} data-testid="story-composer">
        <ConversationMessageInputComposerWidgets testId="story-input" />
        <Text role="help" color="var(--agents-fg-lightest)" translate={false}>
          {"Ask anything…"}
        </Text>
      </div>
    </div>
  </ConversationWidgetProvider>
);
