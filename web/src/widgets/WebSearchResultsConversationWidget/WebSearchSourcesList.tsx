import { Text } from "../../provider/components";
import React from "react";
import { hostnameOf, SourceFavicon } from "./SourceFavicon";
import { toDisplayHostname } from "./shared/display-hostname";
import { WebSearchResultSource } from "./shared/web-search-results-widget-payload";

type WebSearchSourcesListProps = {
  sources: WebSearchResultSource[];
  provider?: string;
  testId?: string;
};

const sourcesStyle: React.CSSProperties = { marginTop: 12, display: "flex", flexDirection: "column", gap: 4 };
const sourcesListStyle: React.CSSProperties = {
  listStyle: "decimal",
  margin: 0,
  paddingLeft: 20,
  display: "flex",
  flexDirection: "column",
  gap: 3,
};
const sourceItemStyle: React.CSSProperties = { display: "flex", alignItems: "center", gap: 6 };
const sourceLinkStyle: React.CSSProperties = {
  color: "var(--agents-primary-500)",
  textDecoration: "none",
  overflow: "hidden",
  textOverflow: "ellipsis",
  whiteSpace: "nowrap",
};

/** The cited sources — a numbered list of links, each opening in a new tab with the site's favicon; falls back to the
 *  hostname when untitled. */
export const WebSearchSourcesList = (props: WebSearchSourcesListProps) => {
  const { sources, provider, testId = "web-search-results-widget" } = props;
  if (sources.length === 0) return null;
  return (
    <div style={sourcesStyle}>
      <Text role="caption" color="var(--agents-fg-light)">
        Sources{provider ? ` · ${provider.toLowerCase()}` : ""}
      </Text>
      <ol style={sourcesListStyle} data-testid={`${testId}-sources`}>
        {sources.map((source, index) => {
          const hostname = hostnameOf(source.url);
          return (
            <li key={`${index}-${source.url}`} style={sourceItemStyle}>
              <SourceFavicon hostname={hostname} />
              <a
                href={source.url}
                target="_blank"
                rel="noopener noreferrer"
                style={sourceLinkStyle}
                data-testid={`${testId}-source-${index}`}
              >
                {source.title?.trim() || toDisplayHostname(hostname)}
              </a>
            </li>
          );
        })}
      </ol>
    </div>
  );
};
