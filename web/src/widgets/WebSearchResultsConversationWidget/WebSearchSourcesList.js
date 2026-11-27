import { Text } from "../../provider/components";
import React from "react";
import { hostnameOf, SourceFavicon } from "./SourceFavicon";
import { toDisplayHostname } from "./shared/display-hostname";
import { AgentsLink } from "../../provider/link-navigation";
const sourcesStyle = { marginTop: 12, display: "flex", flexDirection: "column", gap: 4 };
const sourcesListStyle = {
    listStyle: "decimal",
    margin: 0,
    paddingLeft: 20,
    display: "flex",
    flexDirection: "column",
    gap: 3,
};
const sourceItemStyle = { display: "flex", alignItems: "center", gap: 6 };
const sourceLinkStyle = {
    color: "var(--agents-primary-500)",
    textDecoration: "none",
    overflow: "hidden",
    textOverflow: "ellipsis",
    whiteSpace: "nowrap",
};
/** The cited sources — a numbered list of links, each opening in a new tab with the site's favicon; falls back to the
 *  hostname when untitled. */
export const WebSearchSourcesList = (props) => {
    const { sources, provider, testId = "web-search-results-widget" } = props;
    if (sources.length === 0)
        return null;
    return (<div style={sourcesStyle}>
      <Text role="caption" color="var(--agents-fg-light)">
        Sources{provider ? ` · ${provider.toLowerCase()}` : ""}
      </Text>
      <ol style={sourcesListStyle} data-testid={`${testId}-sources`}>
        {sources.map((source, index) => {
            const hostname = hostnameOf(source.url);
            return (<li key={`${index}-${source.url}`} style={sourceItemStyle}>
              <SourceFavicon hostname={hostname}/>
              <AgentsLink href={source.url} style={sourceLinkStyle} data-testid={`${testId}-source-${index}`}>
                {source.title?.trim() || toDisplayHostname(hostname)}
              </AgentsLink>
            </li>);
        })}
      </ol>
    </div>);
};
