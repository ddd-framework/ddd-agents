import { Icon, InteractiveSurface, Skeleton, Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { useEffect, useMemo, useState } from "react";
import { translateText } from "../../../provider/environment";
import { ModelText } from "../../ModelText/ModelText";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import {
  buildVideoEmbedUrl,
  buildVideoThumbnailUrl,
  buildVideoWatchUrl,
  formatVideoDuration,
  readHttpUrl,
  readImageUrl,
  readMediaSource,
  readVideoId,
  readYouTubeVideoIdFromUrl,
} from "./media-values";
import styles from "./Video.module.scss";
import { useImageLoadState } from "./use-image-load-state";

const readTitle = (value: unknown): string | undefined =>
  typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;

/**
 * The `Video` node (`VideoComposedViewNode.php`): one video
 * as a card — the 16:9 thumbnail with its length, the title, the channel and the source, and "Open video" to the watch
 * page. A connector video (`videoId`) plays in place: the play button swaps the thumbnail for the
 * privacy-enhanced embed, and so does a YouTube watch link written as `url`; any other `url` opens in a new tab. Without a thumbnail a connector video takes its id's
 * thumbnail; a thumbnail that fails falls back to the play glyph on the empty frame. The e-mail capture shows the card
 * without play button and link. A video with neither a `videoId` nor an http(s) `url` reports `failed` and shows its
 * text only.
 */
const VideoNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const { reportNodeStatus, isEmailRender } = runtime;
  const title = readTitle(nodeProps.title);
  const url = readHttpUrl(nodeProps.url);
  const videoId = readVideoId(nodeProps.videoId) ?? readYouTubeVideoIdFromUrl(url);
  const watchUrl = url ?? (videoId ? buildVideoWatchUrl(videoId) : undefined);
  const thumbnailUrl = readImageUrl(nodeProps.thumbnail) ?? (videoId ? buildVideoThumbnailUrl(videoId) : undefined);
  const duration = formatVideoDuration(nodeProps.durationSeconds);
  const channel = readTitle(nodeProps.channel);
  const source = useMemo(() => readMediaSource(nodeProps.source), [nodeProps.source]);
  const isCompact = sizeClass === "compact";
  const [isPlaying, setIsPlaying] = useState(false);
  const {
    loadState: thumbnailLoadState,
    imageRef: thumbnailRef,
    markLoaded: markThumbnailLoaded,
    markFailed: markThumbnailFailed,
  } = useImageLoadState(thumbnailUrl);

  useEffect(() => {
    if (isPending) return;
    if (!title) {
      reportNodeStatus("failed", "INVALID_PROP", "the Video has no title");
      return;
    }
    if (!watchUrl) {
      reportNodeStatus("failed", "INVALID_PROP", `the video "${title}" has neither a videoId nor an http(s) url`);
      return;
    }
    reportNodeStatus("mounted");
  }, [isPending, title, watchUrl, reportNodeStatus]);

  if (isPending) {
    return (
      <span
        className={classNames(styles.video, isCompact && styles.videoCompact)}
        aria-busy="true"
        data-testid={`${testId}-skeleton`}
      >
        <span className={styles.frame}>
          <Skeleton className={styles.frameSkeleton} />
        </span>
      </span>
    );
  }
  if (!title) return null;

  const canPlayInPlace = videoId !== undefined && !isEmailRender && !isCompact;
  const canOpen = watchUrl !== undefined && !isEmailRender;
  const showThumbnail = thumbnailUrl !== undefined && thumbnailLoadState !== "failed";
  const playVideo = () => {
    if (canPlayInPlace) {
      setIsPlaying(true);
      return;
    }
    if (watchUrl) window.open(watchUrl, "_blank", "noopener,noreferrer");
  };
  const metaLine = [channel, duration].filter((metaPart): metaPart is string => metaPart !== undefined).join(" · ");
  const sourceName = source && source.name !== channel ? source.name : undefined;

  const frame =
    isPlaying && videoId ? (
      <span className={styles.frame}>
        <iframe
          className={styles.player}
          data-testid={`${testId}-player`}
          src={buildVideoEmbedUrl(videoId)}
          title={title}
          allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
        />
      </span>
    ) : (
      <InteractiveSurface hoverable={canOpen} className={styles.frame}>
        {showThumbnail ? (
          <img
            ref={thumbnailRef}
            className={styles.thumbnail}
            src={thumbnailUrl}
            alt=""
            loading={isEmailRender ? "eager" : "lazy"}
            decoding="async"
            onLoad={markThumbnailLoaded}
            onError={markThumbnailFailed}
          />
        ) : null}
        {showThumbnail && thumbnailLoadState === "loading" ? <Skeleton className={styles.frameSkeleton} /> : null}
        <span className={classNames(styles.playBadge, isCompact && styles.playBadgeCompact)} aria-hidden="true">
          <Icon color="var(--agents-fg-on-dark)" name="play" />
        </span>
        {duration ? (
          <span className={styles.durationChip}>
            <Text role="caption" color="var(--agents-fg-on-dark)" translate={false}>
              {duration}
            </Text>
          </span>
        ) : null}
        {canOpen ? (
          <button
            type="button"
            className={styles.playButton}
            data-testid={`${testId}-play`}
            aria-label={translateText("Play the video %title%", { title })}
            onClick={playVideo}
          />
        ) : null}
      </InteractiveSurface>
    );

  if (isCompact) {
    return (
      <span className={classNames(styles.video, styles.videoCompact)} data-testid={testId}>
        {frame}
        <ModelText role="caption" ellipsis>
          {title}
        </ModelText>
      </span>
    );
  }
  return (
    <figure className={styles.video} data-testid={testId}>
      {frame}
      <span className={styles.text}>
        <ModelText role="body" weight="bold" className={styles.wrap}>
          {title}
        </ModelText>
        {metaLine ? (
          <ModelText role="caption" color="var(--agents-fg-light)">
            {metaLine}
          </ModelText>
        ) : null}
        {sourceName ? (
          <ModelText role="caption" color="var(--agents-fg-lightest)">
            {sourceName}
          </ModelText>
        ) : null}
      </span>
      {canOpen && watchUrl ? (
        <a
          className={styles.openLink}
          href={watchUrl}
          target="_blank"
          rel="noopener noreferrer"
          data-testid={`${testId}-open-link`}
        >
          <Text role="help" weight="medium" color="var(--agents-fg-cta)">
            {"Open video"}
          </Text>
          <Icon color="var(--agents-fg-cta)" name="arrowRight" />
        </a>
      ) : null}
    </figure>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Video", component: VideoNode };
