import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { useEffect } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readSourceLinkProp, readTextProp } from "./layout-props";
import styles from "./Quote.module.scss";

/**
 * `Quote` (`QuoteComposedViewNode.php`): the quoted words (`text`, or the text nodes in the
 * children), then one caption line — who said it and where it was published, joined by a middle dot; the source opens
 * in a new tab. All of it is the model's business content (never translated). Nothing to quote → `empty`.
 */
const Quote = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, runtime, testId } = props;
  const text = readTextProp(nodeProps.text);
  const attribution = readTextProp(nodeProps.attribution);
  const source = readSourceLinkProp(nodeProps.source);
  const contentNodes = slots.children ?? [];
  const hasQuotedContent = text !== undefined || contentNodes.length > 0;
  const { reportNodeStatus } = runtime;

  useEffect(() => {
    if (hasQuotedContent) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Quote has no quoted text");
  }, [hasQuotedContent, reportNodeStatus]);

  if (!hasQuotedContent) return null;
  const sourceLabel = source?.name ?? source?.url;
  const canOpenSource = source?.url !== undefined && !runtime.isEmailRender;
  return (
    <figure className={styles.quote} data-testid={testId}>
      <blockquote className={styles.quotedContent} cite={source?.url}>
        {text !== undefined ? (
          <Text role="body" translate={false}>
            {escapePlainTextForText(text)}
          </Text>
        ) : null}
        {contentNodes}
      </blockquote>
      {attribution !== undefined || sourceLabel !== undefined ? (
        <figcaption className={styles.caption}>
          {attribution !== undefined ? (
            <Text role="caption" color="var(--agents-fg-light)" translate={false}>
              {escapePlainTextForText(attribution)}
            </Text>
          ) : null}
          {attribution !== undefined && sourceLabel !== undefined ? (
            <Text role="caption" color="var(--agents-fg-lightest)" translate={false} ariaHidden>
              {"·"}
            </Text>
          ) : null}
          {sourceLabel !== undefined && canOpenSource ? (
            <a
              className={styles.sourceLink}
              href={source?.url}
              target="_blank"
              rel="noopener noreferrer"
              data-testid={`${testId}-source`}
            >
              <Text role="caption" color="var(--agents-fg-light)" translate={false}>
                {escapePlainTextForText(sourceLabel)}
              </Text>
            </a>
          ) : null}
          {sourceLabel !== undefined && !canOpenSource ? (
            <Text role="caption" color="var(--agents-fg-light)" translate={false}>
              {escapePlainTextForText(sourceLabel)}
            </Text>
          ) : null}
        </figcaption>
      ) : null}
    </figure>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Quote", component: Quote };
