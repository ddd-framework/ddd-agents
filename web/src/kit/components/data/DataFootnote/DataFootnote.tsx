import { Button, Icon, Text } from "../../../../provider/components";
import { classNames } from "../../../../provider/class-names";
import { escapePlainTextForText } from "../../../../provider/environment";
import styles from "./DataFootnote.module.scss";
import type { ExpressionValue } from "../../../../renderer/widget-tree/expression/evaluate-expression";
import { readObject, readText } from "../data-value-format";

export interface DataFootnoteProps {
  /** The model's muted line under the data (what the figures are, the period). */
  caption?: ExpressionValue;
  /** `{"name","url"}` — where the figures come from; a list of them (a Table whose values come from several pages). */
  source?: ExpressionValue;
  /** The e-mail capture: chip sources are plain text; KPI sources are omitted. */
  isEmailRender: boolean;
  appearance?: "chip" | "quiet";
  testId?: string;
}

/** An http(s) link target; the quiet KPI appearance additionally requires a parseable host. */
const readHttpUrl = (value: unknown): string | undefined =>
  typeof value === "string" && /^https?:\/\//i.test(value) ? value : undefined;

/** The host name of a URL for a source without a name, or undefined for a URL that does not parse. */
const readHostName = (url: string | undefined): string | undefined => {
  if (!url) return undefined;
  try {
    return new URL(url).hostname;
  } catch {
    return undefined;
  }
};

/** One source chip: the name (or the page's host name) and the http(s) page, when it has one. */
interface FootnoteSource {
  name: string;
  url: string | undefined;
}

/** The sources a `source` prop holds — one object or a list of them; entries without a name or a page are left out. */
const readFootnoteSources = (source: ExpressionValue | undefined): FootnoteSource[] => {
  const sourceValues: unknown[] = Array.isArray(source) ? source : [source];
  const footnoteSources: FootnoteSource[] = [];
  for (const sourceValue of sourceValues) {
    const sourceObject = readObject(sourceValue);
    const url = readHttpUrl(sourceObject?.url);
    const name = readText(sourceObject?.name) ?? readHostName(url);
    if (name) footnoteSources.push({ name, url });
  }
  return footnoteSources;
};

interface SourceChipProps {
  footnoteSource: FootnoteSource;
  isEmailRender: boolean;
  appearance: "chip" | "quiet";
  testId?: string;
}

/** A small flat chip: a link opening in a new tab, plain text in the e-mail capture or without a page. */
const SourceChip = (props: SourceChipProps) => {
  const { footnoteSource, isEmailRender, appearance, testId } = props;
  const openSource = () => {
    if (footnoteSource.url) window.open(footnoteSource.url, "_blank", "noopener,noreferrer");
  };
  if (appearance === "quiet") {
    return (
      <Button
        variant="default"
        size="small"
        icon="link"
        iconPosition="left"
        htmlType="button"
        translate={false}
        className={styles.sourceButton}
        testId={testId}
        onClick={openSource}
      >
        {escapePlainTextForText(footnoteSource.name)}
      </Button>
    );
  }
  const className = styles.sourceChip;
  const sourceChipContent = (
    <>
      <Icon pixelSize={12} color="var(--agents-fg-light)" name="link" />
      <Text role="caption" color="var(--agents-fg-light)" translate={false}>
        {escapePlainTextForText(footnoteSource.name)}
      </Text>
    </>
  );
  if (footnoteSource.url && !isEmailRender) {
    return (
      <a className={className} data-testid={testId} href={footnoteSource.url} target="_blank" rel="noopener noreferrer">
        {sourceChipContent}
      </a>
    );
  }
  return (
    <span className={className} data-testid={testId}>
      {sourceChipContent}
    </span>
  );
};

// Charts/tables keep chips (plain text in email). KPI footers show only valid page links and omit sources in email.
export const DataFootnote = (props: DataFootnoteProps) => {
  const { caption, source, isEmailRender, appearance = "chip", testId } = props;
  const captionText = readText(caption);
  const footnoteSources = readFootnoteSources(source)
    .map((footnoteSource, sourceIndex) => ({ footnoteSource, sourceIndex }))
    .filter(({ footnoteSource }) => appearance === "chip" || (!isEmailRender && readHostName(footnoteSource.url)));
  if (!captionText && footnoteSources.length === 0) return null;
  return (
    <div className={classNames(styles.footnote, appearance === "quiet" && styles.quiet)} data-testid={testId}>
      {captionText ? (
        <Text role="caption" color="var(--agents-fg-light)" translate={false}>
          {escapePlainTextForText(captionText)}
        </Text>
      ) : null}
      {footnoteSources.map(({ footnoteSource, sourceIndex }) => (
        <SourceChip
          key={`${sourceIndex}-${footnoteSource.name}`}
          footnoteSource={footnoteSource}
          isEmailRender={isEmailRender}
          appearance={appearance}
          testId={testId && `${testId}-source-${sourceIndex}`}
        />
      ))}
    </div>
  );
};
