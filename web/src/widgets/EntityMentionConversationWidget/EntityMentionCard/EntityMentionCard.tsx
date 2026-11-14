import { Icon, Text } from "../../../provider/components";
import { ModelText } from "../../../kit/ModelText/ModelText";
import type { EntityMentionFields } from "../shared/entity-mention";
import { entityTypeFallbackLabel, entityTypeIcon } from "../shared/entity-mention-presentation";
import styles from "./EntityMentionCard.module.scss";

export interface EntityMentionCardProps {
  mention: EntityMentionFields;
  testId?: string;
}

/**
 * The read-only echo of ONE mentioned entity above the owner's own message: a small elevated card with the entity
 * type's icon in a rounded tile, the caption "Asking about" and the entity's name below it. The name is business
 * content (`ModelText`: never translated, never parsed as markup); without one the line names the type ("This review").
 */
export const EntityMentionCard = (props: EntityMentionCardProps) => {
  const { mention, testId = "entity-mention-chip" } = props;
  return (
    <div className={styles.card} data-testid={testId}>
      <span className={styles.iconTile} data-testid={`${testId}-icon`}>
        <Icon color="var(--agents-fg-cta)" pixelSize={16} name={entityTypeIcon(mention.entityType)} />
      </span>
      <span className={styles.text}>
        <Text
          className={styles.caption}
          role="caption"
          color="var(--agents-fg-lightest)"
          textWrap="nowrap"
          testId={`${testId}-caption`}
        >
          {"Asking about"}
        </Text>
        {mention.label !== null ? (
          <ModelText
            className={styles.label}
            role="help"
            weight="bold"
            textWrap="nowrap"
            testId={`${testId}-label`}
          >
            {mention.label}
          </ModelText>
        ) : (
          <Text
            className={styles.label}
            role="help"
            weight="bold"
            textWrap="nowrap"
            testId={`${testId}-label`}
          >
            {entityTypeFallbackLabel(mention.entityType)}
          </Text>
        )}
      </span>
    </div>
  );
};
