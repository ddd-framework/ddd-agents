import { Icon, Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import { readSafeHttpUrl, readTextNodeContent, useReportTextNodeRenderState, } from "./shared/text-node-values";
import styles from "./Link.module.scss";
import { AgentsLink } from "../../../provider/link-navigation";
/**
 * The `Link` node (`LinkComposedViewNode.php`): the model's text that opens an http(s) page in a new tab
 * (`target="_blank" rel="noopener noreferrer"`), with the new-tab glyph. A url with another scheme
 * (`javascript:`, `data:`, `mailto:`, a relative path) is no link: the text shows alone and the node reports
 * `failed INVALID_PROP`. A link never sends a chat message.
 */
const LinkNode = (props) => {
    const { props: nodeProps, isPending, runtime, testId } = props;
    const content = readTextNodeContent(nodeProps.text);
    const safeUrl = readSafeHttpUrl(nodeProps.url);
    let renderState = "shown";
    if (isPending)
        renderState = "pending";
    else if (content.kind === "invalid" || (content.kind === "text" && safeUrl === null))
        renderState = "invalid";
    else if (content.kind === "missing")
        renderState = "empty";
    useReportTextNodeRenderState(runtime, renderState, "Link", content.kind === "invalid" ? undefined : "the Link has no http(s) url — its text is shown without a link");
    if (isPending)
        return <SkeletonLine width="8em" height={12} radius={4}/>;
    if (content.kind !== "text")
        return null;
    if (safeUrl === null) {
        return (<Text role="body" translate={false} display="inline" className={styles.unlinked} testId={testId}>
        {escapePlainTextForText(content.text)}
      </Text>);
    }
    return (<AgentsLink className={styles.link} href={safeUrl} data-testid={testId}>
      <Text role="body" translate={false} display="inline" className={styles.linkText}>
        {escapePlainTextForText(content.text)}
      </Text>
      <Icon color="var(--agents-fg-light)" className={styles.newTabIcon} pixelSize={14} name="newTab"/>
    </AgentsLink>);
};
export const composedViewComponent = { nodeType: "Link", component: LinkNode };
