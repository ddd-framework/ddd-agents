import { Button, Icon, Text } from "../../../../provider/components";
import { classNames } from "../../../../provider/class-names";
import { escapePlainTextForText } from "../../../../provider/environment";
import styles from "./DataFootnote.module.scss";
import { readObject, readText } from "../data-value-format";
import { AgentsLink, useAgentsLinkNavigation } from "../../../../provider/link-navigation";
/** An http(s) link target; the quiet KPI appearance additionally requires a parseable host. */
const readHttpUrl = (value) => typeof value === "string" && /^https?:\/\//i.test(value) ? value : undefined;
/** The host name of a URL for a source without a name, or undefined for a URL that does not parse. */
const readHostName = (url) => {
    if (!url)
        return undefined;
    try {
        return new URL(url).hostname;
    }
    catch {
        return undefined;
    }
};
/** The sources a `source` prop holds — one object or a list of them; entries without a name or a page are left out. */
const readFootnoteSources = (source) => {
    const sourceValues = Array.isArray(source) ? source : [source];
    const footnoteSources = [];
    for (const sourceValue of sourceValues) {
        const sourceObject = readObject(sourceValue);
        const url = readHttpUrl(sourceObject?.url);
        const name = readText(sourceObject?.name) ?? readHostName(url);
        if (name)
            footnoteSources.push({ name, url });
    }
    return footnoteSources;
};
/** A small flat chip: a link opening in a new tab, plain text in the e-mail capture or without a page. */
const SourceChip = (props) => {
    const { footnoteSource, isEmailRender, appearance, testId } = props;
    const { openLink } = useAgentsLinkNavigation();
    const openSource = () => {
        if (footnoteSource.url)
            openLink(footnoteSource.url);
    };
    if (appearance === "quiet") {
        return (<Button variant="default" size="small" icon="link" iconPosition="left" htmlType="button" translate={false} className={styles.sourceButton} testId={testId} onClick={openSource}>
        {escapePlainTextForText(footnoteSource.name)}
      </Button>);
    }
    const className = styles.sourceChip;
    const sourceChipContent = (<>
      <Icon pixelSize={12} color="var(--agents-fg-light)" name="link"/>
      <Text role="caption" color="var(--agents-fg-light)" translate={false}>
        {escapePlainTextForText(footnoteSource.name)}
      </Text>
    </>);
    if (footnoteSource.url && !isEmailRender) {
        return (<AgentsLink className={className} data-testid={testId} href={footnoteSource.url}>
        {sourceChipContent}
      </AgentsLink>);
    }
    return (<span className={className} data-testid={testId}>
      {sourceChipContent}
    </span>);
};
// Charts/tables keep chips (plain text in email). KPI footers show only valid page links and omit sources in email.
export const DataFootnote = (props) => {
    const { caption, source, isEmailRender, appearance = "chip", testId } = props;
    const captionText = readText(caption);
    const footnoteSources = readFootnoteSources(source)
        .map((footnoteSource, sourceIndex) => ({ footnoteSource, sourceIndex }))
        .filter(({ footnoteSource }) => appearance === "chip" || (!isEmailRender && readHostName(footnoteSource.url)));
    if (!captionText && footnoteSources.length === 0)
        return null;
    return (<div className={classNames(styles.footnote, appearance === "quiet" && styles.quiet)} data-testid={testId}>
      {captionText ? (<Text role="caption" color="var(--agents-fg-light)" translate={false}>
          {escapePlainTextForText(captionText)}
        </Text>) : null}
      {footnoteSources.map(({ footnoteSource, sourceIndex }) => (<SourceChip key={`${sourceIndex}-${footnoteSource.name}`} footnoteSource={footnoteSource} isEmailRender={isEmailRender} appearance={appearance} testId={testId && `${testId}-source-${sourceIndex}`}/>))}
    </div>);
};
