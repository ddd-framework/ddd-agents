import { Icon, InteractiveSurface, Skeleton, Text } from "../../../../provider/components";
import { classNames } from "../../../../provider/class-names";
import { useEffect, useState } from "react";
import { translateText } from "../../../../provider/environment";
import { ModelText } from "../../../ModelText/ModelText";
import type {
  ComposedViewComponentDefinition,
  ComposedViewComponentProps,
} from "../../../composed-view-component-types";
import {
  buildVideoEmbedUrl,
  buildVideoThumbnailUrl,
  buildVideoWatchUrl,
  formatVideoDuration,
  readImageUrl,
  readVideoId,
} from "../media-values";
import { useNumberLocale } from "../../data/use-number-locale";
import { useLayoutNesting } from "../../layout/layout-nesting-context";
import { formatPublishedDay, formatViewCount, readViewCount } from "./video-card-values";
import styles from "./VideoCard.module.scss";
import { useImageLoadState } from "../use-image-load-state";

const readTitle = (value: unknown): string | undefined =>
  typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;

/**
 * The `VideoCard` node (backend `VideoCardComposedViewNode.php`): one YouTube
 * search result as a card — the 16:9 thumbnail with the length on a dark chip and the play glyph, the title, and
 * "channel · views · date" under it. Play swaps the thumbnail for the privacy-enhanced embed in place; "Open on YouTube"
 * opens the watch page in a new tab. A thumbnail that does not load leaves the play glyph on the empty frame. The
 * e-mail capture shows the card without play button and link. Without a title or a valid videoId the node reports
 * `failed` and shows its title only.
 */
const VideoCardNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const { reportNodeStatus, isEmailRender } = runtime;
  const { isInsideSurface, isInsidePressable } = useLayoutNesting();
  const numberLocale = useNumberLocale();
  const title = readTitle(nodeProps.title);
  const videoId = readVideoId(nodeProps.videoId);
  const thumbnailUrl = readImageUrl(nodeProps.thumbnail) ?? (videoId ? buildVideoThumbnailUrl(videoId) : undefined);
  const duration = formatVideoDuration(nodeProps.durationSeconds);
  const channel = readTitle(nodeProps.channel);
  const viewCount = readViewCount(nodeProps.viewCount);
  const publishedDay = formatPublishedDay(nodeProps.publishedAt, numberLocale);
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
      reportNodeStatus("failed", "INVALID_PROP", "the VideoCard has no title");
      return;
    }
    if (!videoId) {
      reportNodeStatus("failed", "INVALID_PROP", `the VideoCard "${title}" has no valid YouTube videoId`);
      return;
    }
    reportNodeStatus("mounted");
  }, [isPending, title, videoId, reportNodeStatus]);

  const cardClassName = classNames(
    styles.card,
    isInsideSurface ? styles.cardFlat : styles.cardElevated,
    isCompact && styles.cardCompact,
  );

  if (isPending) {
    return (
      <span className={cardClassName} aria-busy="true" data-testid={`${testId}-skeleton`}>
        <span className={styles.frame}>
          <Skeleton className={styles.frameSkeleton} />
        </span>
        <span className={styles.text}>
          <Skeleton className={styles.titleSkeleton} />
          <Skeleton className={styles.metaSkeleton} />
        </span>
      </span>
    );
  }
  if (!title) return null;
  if (!videoId) {
    return (
      <span className={cardClassName} data-testid={testId}>
        <ModelText role="body" weight="bold" className={styles.wrap}>
          {title}
        </ModelText>
      </span>
    );
  }

  const watchUrl = videoId ? buildVideoWatchUrl(videoId) : undefined;
  const hasControls = videoId !== undefined && !isEmailRender && !isInsidePressable;
  const canPlayInPlace = hasControls && !isCompact;
  const showThumbnail = thumbnailUrl !== undefined && thumbnailLoadState !== "failed";
  const playVideo = () => {
    if (canPlayInPlace) {
      setIsPlaying(true);
      return;
    }
    if (watchUrl) window.open(watchUrl, "_blank", "noopener,noreferrer");
  };

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
      <InteractiveSurface hoverable={hasControls} className={styles.frame}>
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
        {hasControls ? (
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
      <span className={cardClassName} data-testid={testId}>
        {frame}
        <ModelText role="caption" weight="medium" className={styles.compactTitle}>
          {title}
        </ModelText>
      </span>
    );
  }

  // YouTube's own card layout: the channel on its line, "views · date" under it — a wrapped line never starts with a dot.
  const statisticPhrases = [
    viewCount !== undefined ? (
      <Text
        key="views"
        role="caption"
        color="var(--agents-fg-light)"
        replacements={{ count: formatViewCount(viewCount, numberLocale) }}
        context={viewCount === 1 ? "one" : "other"}
      >
        {"%count% view"}
      </Text>
    ) : null,
    publishedDay ? (
      <Text key="published" role="caption" color="var(--agents-fg-light)" translate={false}>
        {publishedDay}
      </Text>
    ) : null,
  ].filter((statisticPhrase) => statisticPhrase !== null);

  return (
    <article className={cardClassName} data-testid={testId}>
      {frame}
      <span className={styles.text}>
        <span className={styles.title} title={title}>
          <ModelText role="body" weight="bold" className={styles.titleText}>
            {title}
          </ModelText>
        </span>
        {channel ? (
          <ModelText role="caption" color="var(--agents-fg-light)" className={styles.wrap}>
            {channel}
          </ModelText>
        ) : null}
        {statisticPhrases.length > 0 ? (
          <span className={styles.metaLine}>
            {statisticPhrases.map((statisticPhrase, phraseIndex) => (
              <span key={phraseIndex} className={styles.metaPhrase}>
                {phraseIndex > 0 ? (
                  <Text role="caption" color="var(--agents-fg-lightest)" translate={false}>
                    {"·"}
                  </Text>
                ) : null}
                {statisticPhrase}
              </span>
            ))}
          </span>
        ) : null}
      </span>
      {hasControls && watchUrl ? (
        <a
          className={styles.openLink}
          href={watchUrl}
          target="_blank"
          rel="noopener noreferrer"
          data-testid={`${testId}-open-link`}
        >
          <Text role="help" weight="medium" color="var(--agents-fg-cta)">
            {"Open on YouTube"}
          </Text>
          <Icon color="var(--agents-fg-cta)" name="arrowRight" />
        </a>
      ) : null}
    </article>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "VideoCard",
  component: VideoCardNode,
};
