import { Button } from "../../provider/components";
import { useAgentsTexts } from "../../provider/AgentsProvider";
import { classNames } from "../../provider/class-names";
import React from "react";
import styles from "./ConversationScrollToLatestMessageButton.module.scss";
export const ConversationScrollToLatestMessageButton = (props) => {
    const { visible, onClick } = props;
    // The button shows only an icon: its accessible name comes from the package's texts.
    const texts = useAgentsTexts();
    return (<div className={classNames(styles.scrollToLatest, visible ? styles.visible : undefined)}>
      <Button variant="muted" icon="arrowDown" rounded blurred size="extraLarge" onClick={onClick} ariaLabel={texts.translate("Scroll to the latest message")} testId="chat-scroll-to-latest-button"/>
    </div>);
};
