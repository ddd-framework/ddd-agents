import { Text } from "../../provider/components";
import { WebSearchResultSource } from "./shared/web-search-results-widget-payload";
import { hostnameOf, SourceFavicon } from "./SourceFavicon";
import { toDisplayHostname } from "./shared/display-hostname";
import { ModelText } from "../../kit/ModelText/ModelText";
import styles from "./WebSearchReferences.module.scss";

export interface WebSearchReferencesProps {
  sources: WebSearchResultSource[];
  testId?: string;
}

/**
 * The references of a web research answer the agent wrote itself: one link chip per page it used — the
 * site's favicon and its name (the page title instead when several pages of one site are listed, so no two chips read
 * the same), the page title on hover — placed by the agent under its own prose answer. Each opens the
 * page in a new tab. A chip wears the agent tray pill surface (Scheduled / Done) so the sources belong to the board.
 */
export const WebSearchReferences = (props: WebSearchReferencesProps) => {
  const { sources, testId = "web-search-results-widget" } = props;
  if (sources.length === 0) return null;
  const hostnames = sources.map((source) => hostnameOf(source.url));
  return (
    <div className={styles.references} data-testid={testId}>
      <Text role="caption" color="var(--agents-fg-light)">
        {"Sources"}
      </Text>
      <div role="list" className={styles.chips} data-testid={`${testId}-sources`}>
        {sources.map((source, index) => {
          const hostname = hostnames[index];
          const displayHostname = toDisplayHostname(hostname);
          const pageTitle = source.title?.trim() || displayHostname;
          const isRepeatedSite = hostnames.filter((otherHostname) => otherHostname === hostname).length > 1;
          return (
            <div role="listitem" key={`${index}-${source.url}`} className={styles.chipItem}>
              <a
                className={styles.chip}
                href={source.url}
                target="_blank"
                rel="noopener noreferrer"
                title={pageTitle}
                aria-label={pageTitle}
                data-testid={`${testId}-source-${index}`}
              >
                <SourceFavicon hostname={hostname} />
                {isRepeatedSite && source.title?.trim() ? (
                  <ModelText role="caption" weight="bold" className={styles.chipLabel}>
                    {source.title.trim()}
                  </ModelText>
                ) : (
                  <Text
                    role="caption"
                    weight="bold"
                    translate={false}
                    className={styles.chipLabel}
                  >
                    {displayHostname}
                  </Text>
                )}
              </a>
            </div>
          );
        })}
      </div>
    </div>
  );
};
