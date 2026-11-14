import { useIsEmailWidgetRender } from "../../../renderer/ConversationWidgetRenderContext";
import { ConversationWidgetButton } from "../../_shared/ConversationWidgetButton";
import type { EntityMentionTarget } from "../shared/entity-mention";
import { useEntityMentionComposer } from "../shared/use-entity-mention-composer";

export interface EntityMentionAskButtonProps extends EntityMentionTarget {
  testId?: string;
}

/**
 * The detail panel's "Ask about this" button (ChatGPT's "Anschlussfrage stellen"):
 * puts the entity into the chat input as an `EntityMention` chip and sends NOTHING — the owner writes the question,
 * the chip rides in that message as a fence, and the backend reads which entity the question is about. Not a
 * next-step CTA: it posts no prompt. A second click on the same entity changes nothing; the
 * label turns into "Added to your message". Mounted by the `entityDetail` canvas (the shell or a domain body) with
 * the entity the panel shows; renders nothing in the e-mail capture or for a target that names no entity.
 */
export const EntityMentionAskButton = (props: EntityMentionAskButtonProps) => {
  const { entityType, entityId, label, agentSpaceKey, testId = "entity-mention-ask" } = props;
  const isEmailRender = useIsEmailWidgetRender();
  const { isMentioned, mentionEntity } = useEntityMentionComposer();
  const target = { entityType, entityId, label, agentSpaceKey };
  if (isEmailRender) return null;
  const isAlreadyMentioned = isMentioned(target);
  return (
    <ConversationWidgetButton
      variant="primary"
      size="medium"
      icon={isAlreadyMentioned ? "check" : "message"}
      disabled={isAlreadyMentioned}
      onClick={() => mentionEntity(target)}
      testId={testId}
    >
      {isAlreadyMentioned ? "Added to your message" : "Ask about this"}
    </ConversationWidgetButton>
  );
};
