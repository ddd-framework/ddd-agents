import { Markdown, Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import { readSafeHttpUrl, readTextNodeContent, readTextNodeRenderState, useReportTextNodeRenderState, } from "./shared/text-node-values";
import styles from "./Markdown.module.scss";
import { AgentsLink } from "../../../provider/link-navigation";
/** The plain text of a hast element — a heading's inline formatting is not kept (see the heading renderer). */
const readHastText = (node) => {
    if (!node)
        return "";
    if (node.type === "text")
        return node.value ?? "";
    return (node.children ?? []).map(readHastText).join("");
};
/**
 * Every heading level as a section title (`text` bold). The component library's `Text` re-parses element children (a link inside
 * would lose its `href`), so a heading shows its plain text.
 */
const renderHeading = (props) => (<Text role="body" weight="bold" translate={false} display="block" className={styles.heading} headingLevel={4}>
    {escapePlainTextForText(readHastText(props.node))}
  </Text>);
/**
 * The safe subset: no images (an outside request, and a tracking pixel in a model's text), headings no
 * larger than a section title, links only to http(s) pages in a new tab — another scheme renders its text alone. Raw
 * HTML never reaches the DOM: the shared renderer has no raw-HTML plugin, so react-markdown drops it.
 */
const SAFE_MARKDOWN_COMPONENTS = {
    img: () => null,
    h1: renderHeading,
    h2: renderHeading,
    h3: renderHeading,
    h4: renderHeading,
    h5: renderHeading,
    h6: renderHeading,
    a: (props) => {
        const safeUrl = readSafeHttpUrl(props.href);
        if (safeUrl === null)
            return <span>{props.children}</span>;
        return (<AgentsLink className={styles.link} href={safeUrl}>
        {props.children}
      </AgentsLink>);
    },
};
/**
 * The `Markdown` node (`MarkdownComposedViewNode.php`): the model's formatted text — paragraphs, emphasis,
 * lists, quotes, tables, headings — through the shared conversation Markdown renderer, held to the safe subset above
 * and never translated.
 */
const MarkdownNode = (props) => {
    const { props: nodeProps, isPending, runtime, testId } = props;
    const content = readTextNodeContent(nodeProps.markdown);
    useReportTextNodeRenderState(runtime, readTextNodeRenderState(isPending, content), "Markdown");
    if (isPending) {
        return (<div className={styles.skeleton} data-testid={`${testId}-skeleton`}>
        <SkeletonLine width="90%" height={14} radius={4}/>
        <SkeletonLine width="70%" height={14} radius={4}/>
      </div>);
    }
    if (content.kind !== "text")
        return null;
    return (<div className={styles.markdown} data-testid={testId}>
      <Markdown markdown={content.text} translate={false} components={SAFE_MARKDOWN_COMPONENTS}/>
    </div>);
};
export const composedViewComponent = { nodeType: "Markdown", component: MarkdownNode };
