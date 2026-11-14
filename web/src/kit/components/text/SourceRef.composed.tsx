import { Icon, Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { Fragment, useEffect, useId, useState } from "react";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { SkeletonPill } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { SiteFavicon } from "./shared/SiteFavicon";
import { hasAttribution, readSourceRefEntries, SourceRefEntry } from "./shared/source-ref-entries";
import styles from "./SourceRef.module.scss";

interface SourceChipProps {
  entry: SourceRefEntry;
  isEmailRender: boolean;
  testId: string;
}

/** One source as a chip: its favicon and name, a link to its page when it has one (new tab). */
const SourceChip = (props: SourceChipProps) => {
  const { entry, isEmailRender, testId } = props;
  const chipContent = (
    <>
      <SiteFavicon hostname={entry.hostname} siteName={entry.name} isEmailRender={isEmailRender} />
      <Text role="caption" weight="medium" translate={false} className={styles.chipName}>
        {escapePlainTextForText(entry.name)}
      </Text>
    </>
  );
  if (entry.url === null)
    return (
      <span className={styles.chip} data-testid={testId}>
        {chipContent}
      </span>
    );
  return (
    <a
      className={classNames(styles.chip, styles.linkedChip)}
      href={entry.url}
      target="_blank"
      rel="noopener noreferrer"
      title={entry.title ?? entry.name}
      data-testid={testId}
    >
      {chipContent}
    </a>
  );
};

interface AttributionPart {
  text: string;
  /** The http(s) page of this part (the licence); null = plain text. */
  url: string | null;
}

/**
 * The attribution of one source: title · author · licence (linked when it has a page) · source name. Each part is its
 * own `Text` and the licence link sits outside it — the component library's `Text` re-parses element children and drops a link's
 * `href`, `target` and `rel`.
 */
const SourceAttribution = (props: { entry: SourceRefEntry }) => {
  const { entry } = props;
  const parts: AttributionPart[] = [];
  if (entry.title !== null) parts.push({ text: entry.title, url: null });
  if (entry.author !== null) parts.push({ text: entry.author, url: null });
  if (entry.license !== null) parts.push({ text: entry.license, url: entry.licenseUrl });
  parts.push({ text: entry.name, url: null });
  return (
    <span className={styles.attribution}>
      {parts.map((part, partIndex) => {
        const partText = (
          <Text
            role="caption"
            translate={false}
            display="inline"
            color="--agents-fg-light"
            className={styles.attributionText}
          >
            {escapePlainTextForText(part.text)}
          </Text>
        );
        return (
          <Fragment key={partIndex}>
            {partIndex > 0 ? (
              <Text
                role="caption"
                translate={false}
                display="inline"
                color="--agents-fg-light"
                className={styles.attributionText}
              >
                {"·"}
              </Text>
            ) : null}
            {part.url === null ? (
              partText
            ) : (
              <a className={styles.licenseLink} href={part.url} target="_blank" rel="noopener noreferrer">
                {partText}
              </a>
            )}
          </Fragment>
        );
      })}
    </span>
  );
};

interface SourceListProps {
  entries: SourceRefEntry[];
  isEmailRender: boolean;
  testId: string;
}

/** The full source line ("Sources" + one chip per source) and one attribution line per licensed source. */
const SourceList = (props: SourceListProps) => {
  const { entries, isEmailRender, testId } = props;
  const attributedEntries = entries.filter(hasAttribution);
  return (
    <span className={styles.sourceList}>
      <span className={styles.sourceLine}>
        <Text
          role="caption"
          color="--agents-fg-light"
          className={styles.sourceLabel}
          context={entries.length === 1 ? "one" : "other"}
        >
          {"Source"}
        </Text>
        <span role="list" className={styles.chips}>
          {entries.map((entry, entryIndex) => (
            <span role="listitem" key={`${entryIndex}-${entry.name}`} className={styles.chipItem}>
              <SourceChip entry={entry} isEmailRender={isEmailRender} testId={`${testId}-chip-${entryIndex}`} />
            </span>
          ))}
        </span>
      </span>
      {attributedEntries.map((entry, entryIndex) => (
        <SourceAttribution key={`${entryIndex}-${entry.name}`} entry={entry} />
      ))}
    </span>
  );
};

/**
 * The `SourceRef` node (`SourceRefComposedViewNode.php`): where the shown values come from. In a
 * regular slot one line "Sources" with a chip per source (favicon, name, link to its page in a new tab) and, per source
 * with a licence or an author (an image from Wikimedia Commons), an attribution line. In a compact slot
 * (inside a `Text`, a table cell) one chip "WDR +2" that unfolds the full list — an effect-free toggle. Sources
 * without a name are left out and reported; at most `maxRepeatRows` sources are shown.
 */
const SourceRefNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const { reportNodeStatus, isEmailRender } = runtime;
  const maxEntries = composedNodeSchema.budgets.maxRepeatRows;
  const { entries, skippedCount, cutCount, isList } = readSourceRefEntries(nodeProps.sources, maxEntries);
  const [isExpanded, setIsExpanded] = useState(false);
  const expandedListId = useId();

  useEffect(() => {
    if (isPending) reportNodeStatus("pending");
    else if (!isList) reportNodeStatus("failed", "INVALID_PROP", "the SourceRef sources are not a list");
    else if (cutCount > 0) {
      reportNodeStatus("failed", "BUDGET_EXCEEDED", `the SourceRef shows the first ${maxEntries} sources only`);
    } else if (skippedCount > 0) {
      reportNodeStatus("failed", "INVALID_PROP", `${skippedCount} source(s) without a name were left out`);
    } else if (entries.length === 0) reportNodeStatus("empty", "NO_DATA", "the SourceRef has no source to show");
    else reportNodeStatus("mounted");
  }, [reportNodeStatus, isPending, isList, cutCount, skippedCount, entries.length, maxEntries]);

  if (isPending) return <SkeletonPill width={120} height={24} />;
  if (entries.length === 0) return null;
  if (sizeClass !== "compact") {
    return (
      <span className={styles.sourceRef} data-testid={testId}>
        <SourceList entries={entries} isEmailRender={isEmailRender} testId={testId} />
      </span>
    );
  }
  const [firstEntry] = entries;
  const otherSourcesCount = entries.length - 1;
  if (otherSourcesCount === 0 && !hasAttribution(firstEntry)) {
    return (
      <span className={styles.sourceRef} data-testid={testId}>
        <SourceChip entry={firstEntry} isEmailRender={isEmailRender} testId={`${testId}-chip-0`} />
      </span>
    );
  }
  return (
    <span className={styles.sourceRef} data-testid={testId}>
      <button
        type="button"
        className={classNames(styles.chip, styles.toggleChip)}
        aria-expanded={isExpanded}
        aria-controls={expandedListId}
        disabled={isEmailRender}
        data-testid={`${testId}-toggle`}
        onClick={() => setIsExpanded((wasExpanded) => !wasExpanded)}
      >
        <SiteFavicon hostname={firstEntry.hostname} siteName={firstEntry.name} isEmailRender={isEmailRender} />
        <Text
          role="caption"
          weight="medium"
          translate={false}
          className={styles.chipName}
        >
          {escapePlainTextForText(firstEntry.name)}
        </Text>
        {otherSourcesCount > 0 ? (
          <Text role="caption" translate={false} color="--agents-fg-light" className={styles.moreCount}>
            {`+${otherSourcesCount}`}
          </Text>
        ) : null}
        <Icon color="var(--agents-fg-light)" pixelSize={12} name={isExpanded ? "caretUp" : "caretDown"} />
      </button>
      {isExpanded ? (
        <span id={expandedListId} className={styles.expandedList} data-testid={`${testId}-expanded`}>
          <SourceList entries={entries} isEmailRender={isEmailRender} testId={`${testId}-expanded`} />
        </span>
      ) : null}
    </span>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "SourceRef",
  component: SourceRefNode,
};
