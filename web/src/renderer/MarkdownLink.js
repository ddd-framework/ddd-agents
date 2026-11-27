import { AgentsLink } from "../provider/link-navigation";
import styles from "./ConversationMarkdown.module.scss";
/**
 * The link of a chat message (the `a` component `ConversationMarkdown` hands to `Markdown`): a new-tab anchor with a
 * dotted underline, or the host's own navigation (`AgentsEnvironment.onNavigate`). A host's `Markdown` replacement may
 * wrap it (`components.a`). `node` is dropped so react-markdown's hast node never lands on the DOM element.
 */
export const MarkdownLink = (props) => {
    const { node, children, className, ...anchorProps } = props;
    return (<AgentsLink {...anchorProps} className={className ? `${styles.markdownLink} ${className}` : styles.markdownLink}>
      {children}
    </AgentsLink>);
};
