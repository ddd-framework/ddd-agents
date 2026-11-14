import { Markdown, Text, type AgentsMarkdownProps } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import type { ReactNode } from "react";
import { readSafeHttpUrl } from "../../../kit/components/text/shared/text-node-values";
import { remarkDraftLineBreaks } from "../shared/remark-draft-line-breaks";
import styles from "./WritingDraftWidget.module.scss";

interface DraftMarkdownElementProps {
  children?: ReactNode;
  /** The hast element react-markdown renders (its text is what a heading shows). */
  node?: DraftMarkdownHastNode;
}

interface DraftMarkdownHastNode {
  type: string;
  value?: string;
  children?: DraftMarkdownHastNode[];
}

/** The plain text of a hast element — a heading's inline formatting is not kept. */
const readHastText = (node: DraftMarkdownHastNode | undefined): string => {
  if (!node) return "";
  if (node.type === "text") return node.value ?? "";
  return (node.children ?? []).map(readHastText).join("");
};

/** Every heading level as a bold line at the body size — a draft has no page-sized headlines. */
const renderHeading = (props: DraftMarkdownElementProps) => (
  <Text
    role="body"
    weight="bold"
    translate={false}
    display="block"
    className={styles.bodyHeading}
    headingLevel={4}
  >
    {escapePlainTextForText(readHastText(props.node))}
  </Text>
);

/**
 * The safe subset of the draft's Markdown (the kit `Markdown` node's rule): no images (an outside request,
 * a tracking pixel in a model's text), headings at most a bold line, links only to http(s) pages in a new tab — a
 * `javascript:` or `mailto:` link renders its label alone. Raw HTML never reaches the DOM: the shared renderer has no
 * raw-HTML plugin, so react-markdown drops it.
 */
const SAFE_DRAFT_MARKDOWN_COMPONENTS = {
  img: () => null,
  h1: renderHeading,
  h2: renderHeading,
  h3: renderHeading,
  h4: renderHeading,
  h5: renderHeading,
  h6: renderHeading,
  a: (props: DraftMarkdownElementProps & { href?: string }) => {
    const safeUrl = readSafeHttpUrl(props.href);
    if (safeUrl === null) return <span>{props.children}</span>;
    return (
      <a className={styles.bodyLink} href={safeUrl} target="_blank" rel="noopener noreferrer">
        {props.children}
      </a>
    );
  },
} as NonNullable<AgentsMarkdownProps["components"]>;

const DRAFT_REMARK_PLUGINS: NonNullable<AgentsMarkdownProps["remarkPlugins"]> = [remarkDraftLineBreaks];

export interface WritingDraftBodyProps {
  /** The draft text as Markdown, the model's words or the owner's edit. */
  markdown: string;
}

/**
 * The draft text, formatted: paragraphs, emphasis, lists and quotes through the shared conversation renderer; a single
 * line break stays a line break (a signature, an address block).
 */
export const WritingDraftBody = (props: WritingDraftBodyProps) => {
  const { markdown } = props;
  return (
    <div className={styles.body} data-testid="writing-draft-body">
      <Markdown
        markdown={markdown}
        translate={false}
        components={SAFE_DRAFT_MARKDOWN_COMPONENTS}
        remarkPlugins={DRAFT_REMARK_PLUGINS}
      />
    </div>
  );
};
