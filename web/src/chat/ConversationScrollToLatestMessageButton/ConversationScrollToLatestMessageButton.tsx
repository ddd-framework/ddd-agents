import { Button } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import React from "react";
import styles from "./ConversationScrollToLatestMessageButton.module.scss";

type ConversationScrollToLatestMessageButtonProps = {
  visible: boolean;
  onClick: () => void;
};

export const ConversationScrollToLatestMessageButton = (props: ConversationScrollToLatestMessageButtonProps) => {
  const { visible, onClick } = props;
  return (
    <div className={classNames(styles.scrollToLatest, visible ? styles.visible : undefined)}>
      <Button
        variant="muted"
        icon="arrowDown"
        rounded
        blurred
        size="extraLarge"
        onClick={onClick}
      />
    </div>
  );
};
