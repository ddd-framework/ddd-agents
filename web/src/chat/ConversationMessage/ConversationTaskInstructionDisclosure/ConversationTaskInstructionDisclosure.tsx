import { Button, Text } from "../../../provider/components";
import React, { useState } from "react";
import styles from "./ConversationTaskInstructionDisclosure.module.scss";

export interface ConversationTaskInstructionDisclosureProps {
  /** The machine-composed seed instruction of a schedule-spawned run — the full text revealed on expand. */
  instructionText: string;
}

/**
 * Replaces the owner bubble for the FIRST user message of a SCHEDULE-spawned run conversation (UX overhaul W4
 * item 19). That message is the machine-composed task instruction (the schedule's task text + the previous run's
 * outcome line, see AIConversationSchedule::composeSeedUserMessageText) — NOT something the owner typed, so an
 * owner bubble would misattribute it as the owner's own words. Collapsed by default to a muted
 * "Show task instruction" disclosure; expanding reveals the full instruction text for inspection.
 */
export const ConversationTaskInstructionDisclosure = ({
  instructionText,
}: ConversationTaskInstructionDisclosureProps) => {
  const [expanded, setExpanded] = useState(false);

  return (
    <div className={styles.disclosure}>
      {expanded ? (
        <div className={styles.instructionPanel}>
          <Text role="caption" color="var(--agents-fg-light)" allowNewLines translate={false}>
            {instructionText}
          </Text>
        </div>
      ) : null}
      <Button variant="muted" onClick={() => setExpanded((wasExpanded) => !wasExpanded)}>
        {expanded ? "Hide task instruction" : "Show task instruction"}
      </Button>
    </div>
  );
};
