import { Icon } from "../../provider/components";
import React, { useState } from "react";

const sourceIconStyle: React.CSSProperties = { fontSize: 14, flexShrink: 0 };
const faviconStyle: React.CSSProperties = { width: 16, height: 16, flexShrink: 0, borderRadius: 3 };

/** Best-effort hostname for an untitled source, so the link still reads as a place rather than a raw URL. */
export const hostnameOf = (url: string): string => {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return url;
  }
};

/**
 * The site's favicon (the small per-site icon WhatsApp/Slack show on a link), resolved from the Google favicon service
 * by domain. Falls back to the generic link glyph if the icon fails to load (no favicon, offline) — never a broken
 * image.
 */
export const SourceFavicon = ({ hostname }: { hostname: string }) => {
  const [failed, setFailed] = useState(false);
  if (failed || !hostname) {
    return (
      <Icon color="var(--agents-fg-light)" style={sourceIconStyle} name="link" />
    );
  }
  return (
    <img
      src={`https://www.google.com/s2/favicons?domain=${encodeURIComponent(hostname)}&sz=32`}
      alt=""
      loading="lazy"
      style={faviconStyle}
      onError={() => setFailed(true)}
    />
  );
};
