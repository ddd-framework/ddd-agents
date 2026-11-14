import { Button, Icon, Text } from "../../../provider/components";
import { ModelText } from "../../../kit/ModelText/ModelText";
import type { EntityMentionFields } from "../shared/entity-mention";
import styles from "./EntityMentionChip.module.scss";

export interface EntityMentionChipProps {
  mention: EntityMentionFields;
  /** The composer's ✕; omitted → no remove button. */
  onRemove?: () => void;
  testId?: string;
}

/**
 * ONE mentioned entity as a chip — in the chat input ({@link EntityMentionComposerWidget}): the detail glyph, the label "About:" and the name.
 * The name is what the panel showed — business content through `ModelText` (never translated, never parsed as
 * markup; a `replacements` value is parsed by the component library, so it is not used for it); without one the chip says "About
 * this entry".
 */
export const EntityMentionChip = (props: EntityMentionChipProps) => {
  const { mention, onRemove, testId = "entity-mention-chip" } = props;
  return (
    <span className={styles.chip} data-testid={testId}>
      <Icon color="var(--agents-fg-light)" className={styles.icon} pixelSize={14} name="details" />
      {mention.label !== null ? (
        <>
          <Text className={styles.prefix} role="help" color="var(--agents-fg-light)" textWrap="nowrap">
            {"About:"}
          </Text>
          <ModelText className={styles.label} role="help" textWrap="nowrap">
            {mention.label}
          </ModelText>
        </>
      ) : (
        <Text className={styles.label} role="help" textWrap="nowrap">
          {"About this entry"}
        </Text>
      )}
      {onRemove && (
        <Button
          className={styles.remove}
          variant="muted"
          size="small"
          icon="close"
          onClick={onRemove}
          testId={`${testId}-remove`}
          rounded
        />
      )}
    </span>
  );
};
