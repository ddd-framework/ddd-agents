import { Icon } from "../../../../provider/components";
import { useState } from "react";
import { buildFaviconUrl } from "./text-node-values";
import styles from "./SiteFavicon.module.scss";

export interface SiteFaviconProps {
  /** The site, or null when the value named none (the link glyph shows). */
  hostname: string | null;
  /** The site's name for the accessible label and the hover; the host name when absent. */
  siteName?: string;
  /** The e-mail capture fails on a broken image, and a favicon is an outside request: the capture shows the glyph. */
  isEmailRender: boolean;
}

/**
 * A site's favicon for the kit's text components (`Favicon`, `SourceRef`): the Google favicon service by host name, the
 * generic link glyph when there is no site, the image fails to load, or the e-mail capture renders — never a broken image.
 */
export const SiteFavicon = (props: SiteFaviconProps) => {
  const { hostname, siteName, isEmailRender } = props;
  const [hasFailedToLoad, setHasFailedToLoad] = useState(false);
  const accessibleName = siteName ?? hostname ?? undefined;
  if (hostname === null || hasFailedToLoad || isEmailRender) {
    return (
      <span
        className={styles.favicon}
        role={accessibleName ? "img" : undefined}
        aria-label={accessibleName}
        title={accessibleName}
      >
        <Icon color="var(--agents-fg-light)" pixelSize={14} name="link" />
      </span>
    );
  }
  return (
    <img
      className={styles.favicon}
      src={buildFaviconUrl(hostname)}
      alt={accessibleName ?? ""}
      title={accessibleName}
      loading="lazy"
      onError={() => setHasFailedToLoad(true)}
    />
  );
};
