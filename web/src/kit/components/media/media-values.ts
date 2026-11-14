import { openImageGallery } from "../../../provider/environment";

/**
 * The values the media kit components (`Image`, `ImageGroup`, `Video`) read from their resolved props.
 * Every reader is total: a value that does not fit is `undefined` (or dropped from a list and counted), never a throw —
 * the component reports what it lost and renders the rest.
 */

/** Where a picture or a video was found — the chip over an image, the source line under a video. */
export interface MediaSource {
  name: string;
  url?: string;
}

/** One picture of an `Image` node or of an `ImageGroup` list. */
export interface MediaImage {
  src: string;
  alt: string;
  title?: string;
  caption?: string;
  source?: MediaSource;
}

/** The pictures of a list prop: the usable ones, how many were dropped (no `src` / `alt`) and how many past the row cap. */
export interface MediaImageList {
  images: MediaImage[];
  droppedCount: number;
  cappedCount: number;
}

const readText = (value: unknown): string | undefined =>
  typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

/** An `http(s)` URL, unchanged; anything else (a `javascript:` link, a relative path, a typo) is `undefined`. */
export const readHttpUrl = (value: unknown): string | undefined => {
  const text = readText(value);
  if (!text) return undefined;
  try {
    const url = new URL(text);
    return url.protocol === "https:" || url.protocol === "http:" ? text : undefined;
  } catch {
    return undefined;
  }
};

/** An image address: an `http(s)` URL or an inline `data:image/…` URI (the fixtures, a generated picture). */
export const readImageUrl = (value: unknown): string | undefined => {
  const text = readText(value);
  if (text && /^data:image\/[a-z0-9.+-]+[;,]/i.test(text)) return text;
  return readHttpUrl(value);
};

/** `{"name","url"}`; a source with only a URL is named by its host. */
export const readMediaSource = (value: unknown): MediaSource | undefined => {
  if (!isRecord(value)) return undefined;
  const url = readHttpUrl(value.url);
  const name = readText(value.name) ?? (url ? new URL(url).hostname.replace(/^www\./, "") : undefined);
  if (!name) return undefined;
  return url ? { name, url } : { name };
};

/** One picture from a record carrying `src` and `alt` (an `Image` node's props or one entry of an `ImageGroup`). */
export const readMediaImage = (value: unknown): MediaImage | undefined => {
  if (!isRecord(value)) return undefined;
  const src = readImageUrl(value.src);
  const alt = readText(value.alt);
  if (!src || !alt) return undefined;
  const title = readText(value.title);
  const caption = readText(value.caption);
  const source = readMediaSource(value.source);
  return {
    src,
    alt,
    ...(title ? { title } : {}),
    ...(caption ? { caption } : {}),
    ...(source ? { source } : {}),
  };
};

/** The pictures of a list prop, at most `maxImages` (the schema's `maxRepeatRows`). Not a list = no pictures. */
export const readMediaImageList = (value: unknown, maxImages: number): MediaImageList => {
  const entries = Array.isArray(value) ? value : [];
  const usableImages = entries.map(readMediaImage).filter((image): image is MediaImage => image !== undefined);
  const images = usableImages.slice(0, maxImages);
  return {
    images,
    droppedCount: entries.length - usableImages.length,
    cappedCount: usableImages.length - images.length,
  };
};

/** The frames of the node schema (`ImageComposedViewNode::ASPECT_RATIOS`); each has its class in `MediaImageTile.module.scss`. */
export const MEDIA_ASPECT_RATIOS = ["1:1", "4:3", "3:2", "5:4", "16:9", "3:4"] as const;

export type MediaAspectRatio = (typeof MEDIA_ASPECT_RATIOS)[number];

/** The frame a prop names, or `fallbackAspectRatio` when it names none of the schema's frames. */
export const readAspectRatio = (value: unknown, fallbackAspectRatio: MediaAspectRatio): MediaAspectRatio =>
  MEDIA_ASPECT_RATIOS.find((aspectRatio) => aspectRatio === value) ?? fallbackAspectRatio;

/** How a picture fills its frame: `cover` crops a photo, `contain` shows a logo whole. */
export type MediaImageFit = "cover" | "contain";

export const readImageFit = (value: unknown): MediaImageFit => (value === "contain" ? "contain" : "cover");

/** A positive whole number prop (`maxVisible`), or the fallback. */
export const readPositiveCount = (value: unknown, fallbackCount: number): number =>
  typeof value === "number" && Number.isFinite(value) && value >= 1 ? Math.floor(value) : fallbackCount;

/**
 * Opens the app's image lightbox over `images`, starting at `startIndex` — the same lightbox the chat's image and logo
 * galleries open. Every entry carries its position as its id.
 */
export const openMediaImagesLightbox = (images: MediaImage[], startIndex: number): void => {
  if (images.length === 0) return;
  const gallery = images.map((image, imageIndex) => ({
    id: `composed-view-image-${imageIndex}`,
    url: image.src,
    name: image.title ?? image.alt,
  }));
  openImageGallery(gallery, Math.min(Math.max(startIndex, 0), gallery.length - 1));
};

/** `1418` → `23:38`, `3725` → `1:02:05`; no length for a missing, negative or non-finite value. */
export const formatVideoDuration = (durationSeconds: unknown): string | undefined => {
  if (typeof durationSeconds !== "number" || !Number.isFinite(durationSeconds) || durationSeconds < 0) return undefined;
  const wholeSeconds = Math.floor(durationSeconds);
  const hours = Math.floor(wholeSeconds / 3600);
  const minutes = Math.floor((wholeSeconds % 3600) / 60);
  const paddedSeconds = String(wholeSeconds % 60).padStart(2, "0");
  return hours > 0 ? `${hours}:${String(minutes).padStart(2, "0")}:${paddedSeconds}` : `${minutes}:${paddedSeconds}`;
};

/**
 * A video id of the video connector (YouTube): letters, digits, `-` and `_` only — so it goes into the embed,
 * watch and thumbnail URLs without escaping.
 */
export const readVideoId = (value: unknown): string | undefined => {
  const text = readText(value);
  return text && /^[A-Za-z0-9_-]{6,32}$/.test(text) ? text : undefined;
};

const YOUTUBE_WATCH_HOSTS = ["youtube.com", "www.youtube.com", "m.youtube.com", "music.youtube.com"];

/**
 * The video id of a YouTube link the model wrote as `url` (`youtube.com/watch?v=…`, `youtu.be/…`, `/shorts/…`,
 * `/embed/…`), so such a video plays in place like a connector video; any other link is `undefined`.
 */
export const readYouTubeVideoIdFromUrl = (url: string | undefined): string | undefined => {
  if (!url) return undefined;
  let parsedUrl: URL;
  try {
    parsedUrl = new URL(url);
  } catch {
    return undefined;
  }
  const host = parsedUrl.hostname.toLowerCase();
  if (host === "youtu.be") return readVideoId(parsedUrl.pathname.split("/")[1]);
  if (!YOUTUBE_WATCH_HOSTS.includes(host)) return undefined;
  if (parsedUrl.pathname === "/watch") return readVideoId(parsedUrl.searchParams.get("v"));
  const pathMatch = parsedUrl.pathname.match(/^\/(?:shorts|embed|live)\/([^/]+)/);
  return pathMatch ? readVideoId(pathMatch[1]) : undefined;
};

/** The privacy-enhanced embed of a connector video; it autoplays, because the owner pressed play to load it. */
export const buildVideoEmbedUrl = (videoId: string): string =>
  `https://www.youtube-nocookie.com/embed/${videoId}?autoplay=1&rel=0`;

export const buildVideoWatchUrl = (videoId: string): string => `https://www.youtube.com/watch?v=${videoId}`;

export const buildVideoThumbnailUrl = (videoId: string): string => `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`;
