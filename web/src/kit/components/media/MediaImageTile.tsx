import { Icon, InteractiveSurface, Skeleton, Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { translateText } from "../../../provider/environment";
import { ModelText } from "../../ModelText/ModelText";
import type { MediaAspectRatio, MediaImage, MediaImageFit } from "./media-values";
import styles from "./MediaImageTile.module.scss";
import { useImageLoadState } from "./use-image-load-state";

export interface MediaImageTileProps {
  image: MediaImage;
  aspectRatio: MediaAspectRatio;
  fit?: MediaImageFit;
  /** Load at once instead of lazily — the e-mail capture, which shoots the whole widget. */
  isEager?: boolean;
  /** Opens the lightbox at this picture; without it the tile is a plain picture (the e-mail capture). */
  onOpen?: () => void;
  /** The pictures the gallery does not show — counted on this tile as "+N". */
  hiddenImageCount?: number;
  /** The compact radius and no source chip — a thumbnail in a compact slot or a dense grid. */
  isCompact?: boolean;
  /** Called once when the picture fails to load; the tile then shows its alt text instead. */
  onLoadFailed?: () => void;
  testId?: string;
}

const ASPECT_RATIO_CLASS_NAMES: Readonly<Record<MediaAspectRatio, string>> = {
  "1:1": styles.ratioSquare,
  "4:3": styles.ratioFourByThree,
  "3:2": styles.ratioThreeByTwo,
  "5:4": styles.ratioFiveByFour,
  "16:9": styles.ratioSixteenByNine,
  "3:4": styles.ratioThreeByFour,
};

/**
 * One picture of the media kit: a frame in the picture's aspect ratio on `--agents-bg-disabled`, a
 * skeleton in the frame's shape until the picture painted, the picture lazily loaded (`object-fit` cover or contain),
 * the source as a dark chip over its corner and, on the last visible tile of a gallery, the count of the hidden rest.
 * A picture that fails to load is replaced by its alt text — no broken image, and the e-mail capture never waits on a
 * missing asset. A tile that opens the lightbox is a native button inside the hoverable surface.
 */
export const MediaImageTile = (props: MediaImageTileProps) => {
  const {
    image,
    aspectRatio,
    fit = "cover",
    isEager = false,
    onOpen,
    hiddenImageCount = 0,
    isCompact = false,
    onLoadFailed,
    testId,
  } = props;
  const { loadState, imageRef, markLoaded, markFailed: markImageFailed } = useImageLoadState(image.src);

  const markFailed = () => {
    markImageFailed();
    onLoadFailed?.();
  };

  const frameClassName = classNames(
    styles.frame,
    ASPECT_RATIO_CLASS_NAMES[aspectRatio],
    isCompact && styles.frameCompact,
  );

  const frameContent = (
    <>
      {loadState === "failed" ? (
        <span className={styles.fallback}>
          <Icon color="var(--agents-fg-light)" name="image" />
          <ModelText role="caption" color="var(--agents-fg)" className={styles.fallbackText}>
            {image.alt}
          </ModelText>
        </span>
      ) : (
        <img
          ref={imageRef}
          className={classNames(styles.image, fit === "contain" && styles.imageContain)}
          src={image.src}
          alt={image.alt}
          loading={isEager ? "eager" : "lazy"}
          decoding="async"
          onLoad={markLoaded}
          onError={markFailed}
        />
      )}
      {loadState === "loading" ? <Skeleton className={styles.skeleton} /> : null}
      {image.source && !isCompact ? (
        <span className={styles.sourceChip}>
          <ModelText role="caption" color="var(--agents-fg-on-dark)" ellipsis>
            {image.source.name}
          </ModelText>
        </span>
      ) : null}
      {hiddenImageCount > 0 ? (
        <span className={styles.hiddenCountChip}>
          <Icon color="var(--agents-fg-on-dark)" name="photo" />
          <Text role="caption" color="var(--agents-fg-on-dark)" translate={false}>
            {`+${hiddenImageCount}`}
          </Text>
        </span>
      ) : null}
    </>
  );

  if (!onOpen) {
    return (
      <span className={frameClassName} data-testid={testId}>
        {frameContent}
      </span>
    );
  }
  const openLabel =
    hiddenImageCount > 0
      ? translateText(
          "Open the gallery at %alt%, %count% more picture",
          { alt: image.alt, count: hiddenImageCount },
          hiddenImageCount === 1 ? "one" : "other",
        )
      : translateText("Open the picture %alt% full size", { alt: image.alt });
  return (
    <InteractiveSurface hoverable className={frameClassName}>
      {frameContent}
      <button
        type="button"
        className={styles.openButton}
        aria-label={openLabel}
        onClick={onOpen}
        data-testid={testId ? `${testId}-open` : undefined}
      />
    </InteractiveSurface>
  );
};
