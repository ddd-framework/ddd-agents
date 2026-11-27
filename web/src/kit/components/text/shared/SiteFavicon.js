import { Icon } from "../../../../provider/components";
import { useState } from "react";
import { buildFaviconUrl } from "./text-node-values";
import styles from "./SiteFavicon.module.scss";
/**
 * A site's favicon for the kit's text components (`Favicon`, `SourceRef`): the Google favicon service by host name, the
 * generic link glyph when there is no site, the image fails to load, or the e-mail capture renders — never a broken image.
 */
export const SiteFavicon = (props) => {
    const { hostname, siteName, isEmailRender } = props;
    const [hasFailedToLoad, setHasFailedToLoad] = useState(false);
    const accessibleName = siteName ?? hostname ?? undefined;
    if (hostname === null || hasFailedToLoad || isEmailRender) {
        return (<span className={styles.favicon} role={accessibleName ? "img" : undefined} aria-label={accessibleName} title={accessibleName}>
        <Icon color="var(--agents-fg-light)" pixelSize={14} name="link"/>
      </span>);
    }
    return (<img className={styles.favicon} src={buildFaviconUrl(hostname)} alt={accessibleName ?? ""} title={accessibleName} loading="lazy" onError={() => setHasFailedToLoad(true)}/>);
};
