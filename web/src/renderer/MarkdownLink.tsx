import React from "react";
import styles from "./ConversationMarkdown.module.scss";

export type MarkdownLinkProps = React.AnchorHTMLAttributes<HTMLAnchorElement> & {
  /** The hast node react-markdown passes to every custom component; not a valid DOM attribute. */
  node?: unknown;
};

// Render every markdown link as a new-tab anchor with a dashed underline. `node` is dropped before the
// rest is spread onto <a> so react-markdown's hast node never lands on the DOM element.
export const MarkdownLink = ({ node, children, ...anchorProps }: MarkdownLinkProps) => (
  <a {...anchorProps} target="_blank" rel="noopener noreferrer" className={styles.markdownLink}>
    {children}
  </a>
);
