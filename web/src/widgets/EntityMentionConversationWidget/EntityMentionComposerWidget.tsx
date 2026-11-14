import { useMemo } from "react";
import type { ComposerConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { EntityMentionChip } from "./EntityMentionChip/EntityMentionChip";
import { normalizeEntityMentionFields } from "./shared/entity-mention";

/**
 * The registry `composerComponent` of `EntityMention`: the entity chip in the chat input until the owner
 * sends — its ✕ (or Backspace on an empty input) takes the mention out. Fields that name no entity render nothing.
 */
export const EntityMentionComposerWidget = (props: ComposerConversationWidgetProps) => {
  const { fields, remove, testId = "entity-mention" } = props;
  const mention = useMemo(() => normalizeEntityMentionFields(fields), [fields]);
  if (mention === null) return null;
  return <EntityMentionChip mention={mention} onRemove={remove} testId={`${testId}-chip`} />;
};
