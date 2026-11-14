import { Icon, InteractiveSurface, Skeleton, Text, type AgentsIconName } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { KeyboardEvent, useEffect, useMemo, useState } from "react";
import { translateText } from "../../../provider/environment";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { MediaCaption } from "./MediaCaption";
import { MediaImageTile } from "./MediaImageTile";
import {
  MediaAspectRatio,
  MediaImage,
  openMediaImagesLightbox,
  readAspectRatio,
  readMediaImageList,
  readPositiveCount,
} from "./media-values";
import styles from "./ImageGroup.module.scss";

/** Tiles a grid shows before its last tile counts the hidden rest (`maxVisible`); a compact strip shows three. */
const DEFAULT_MAX_VISIBLE_TILES = 4;
const COMPACT_MAX_VISIBLE_TILES = 3;
/** Skeleton tiles while the bound pictures are pending. */
const PENDING_TILE_COUNT = 4;

type ImageGroupLayout = "grid" | "carousel" | "magazine";

const readImageGroupLayout = (value: unknown): ImageGroupLayout =>
  value === "carousel" || value === "magazine" ? value : "grid";

interface ImageGroupLayoutProps {
  images: MediaImage[];
  aspectRatio: MediaAspectRatio;
  isEmailRender: boolean;
  onImageLoadFailed: (imageIndex: number) => void;
  testId: string;
}

const buildOpenImageHandler = (images: MediaImage[], imageIndex: number, isEmailRender: boolean) =>
  isEmailRender ? undefined : () => openMediaImagesLightbox(images, imageIndex);

/** The grid (ChatGPT 44): up to `maxVisible` tiles, the last one counting the hidden rest; every tile opens the lightbox. */
const ImageGroupGrid = (props: ImageGroupLayoutProps & { maxVisibleTiles: number; isCompact: boolean }) => {
  const { images, aspectRatio, isEmailRender, onImageLoadFailed, maxVisibleTiles, isCompact, testId } = props;
  const visibleImages = images.slice(0, maxVisibleTiles);
  const hiddenImageCount = images.length - visibleImages.length;
  return (
    <div className={classNames(styles.grid, isCompact && styles.gridCompact)} data-testid={testId}>
      {visibleImages.map((image, imageIndex) => (
        <figure key={`${imageIndex}:${image.src}`} className={styles.tile}>
          <MediaImageTile
            testId={`${testId}-tile-${imageIndex}`}
            image={image}
            aspectRatio={aspectRatio}
            isEager={isEmailRender}
            isCompact={isCompact}
            hiddenImageCount={imageIndex === visibleImages.length - 1 ? hiddenImageCount : 0}
            onOpen={buildOpenImageHandler(images, imageIndex, isEmailRender)}
            onLoadFailed={() => onImageLoadFailed(imageIndex)}
          />
          {isCompact ? null : <MediaCaption title={image.title} caption={image.caption} />}
        </figure>
      ))}
    </div>
  );
};

/** The magazine (ChatGPT 15): the first picture as a 16:9 hero, the others as tiles under it, each with its text. */
const ImageGroupMagazine = (props: ImageGroupLayoutProps & { heroAspectRatio: MediaAspectRatio }) => {
  const { images, aspectRatio, heroAspectRatio, isEmailRender, onImageLoadFailed, testId } = props;
  const [heroImage, ...tileImages] = images;
  return (
    <div className={styles.magazine} data-testid={testId}>
      <figure className={styles.tile}>
        <MediaImageTile
          testId={`${testId}-tile-0`}
          image={heroImage}
          aspectRatio={heroAspectRatio}
          isEager={isEmailRender}
          onOpen={buildOpenImageHandler(images, 0, isEmailRender)}
          onLoadFailed={() => onImageLoadFailed(0)}
        />
        <MediaCaption title={heroImage.title} caption={heroImage.caption} />
      </figure>
      {tileImages.length > 0 ? (
        <div className={styles.grid}>
          {tileImages.map((image, tileIndex) => (
            <figure key={`${tileIndex}:${image.src}`} className={styles.tile}>
              <MediaImageTile
                testId={`${testId}-tile-${tileIndex + 1}`}
                image={image}
                aspectRatio={aspectRatio}
                isEager={isEmailRender}
                onOpen={buildOpenImageHandler(images, tileIndex + 1, isEmailRender)}
                onLoadFailed={() => onImageLoadFailed(tileIndex + 1)}
              />
              <MediaCaption title={image.title} caption={image.caption} />
            </figure>
          ))}
        </div>
      ) : null}
    </div>
  );
};

interface CarouselStepButtonProps {
  label: string;
  icon: AgentsIconName;
  className: string;
  isDisabled: boolean;
  onStep: () => void;
  testId: string;
}

/** Previous / next of the carousel: a round dark control over the picture, the hover layer from GlobalStateComponent. */
const CarouselStepButton = (props: CarouselStepButtonProps) => {
  const { label, icon, className, isDisabled, onStep, testId } = props;
  return (
    <InteractiveSurface
      hoverable={!isDisabled}
      className={classNames(styles.carouselButton, className, isDisabled && styles.carouselButtonDisabled)}
    >
      <button
        type="button"
        className={styles.carouselButtonTarget}
        aria-label={label}
        data-testid={testId}
        disabled={isDisabled}
        onClick={onStep}
      >
        <Icon color="var(--agents-fg-on-dark)" name={icon} />
      </button>
    </InteractiveSurface>
  );
};

/**
 * The carousel (ChatGPT 09): one large picture at a time with its text, previous / next over its sides, the position
 * as "2 / 5" and the arrow keys. The e-mail capture shows the first picture without the controls.
 */
const ImageGroupCarousel = (props: ImageGroupLayoutProps) => {
  const { images, aspectRatio, isEmailRender, onImageLoadFailed, testId } = props;
  const [shownImageIndex, setShownImageIndex] = useState(0);
  const lastImageIndex = images.length - 1;
  const currentImageIndex = Math.min(shownImageIndex, lastImageIndex);
  const currentImage = images[currentImageIndex];
  const hasControls = !isEmailRender && images.length > 1;
  const showImage = (imageIndex: number) => setShownImageIndex(Math.min(Math.max(imageIndex, 0), lastImageIndex));
  const showImageForKey = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === "ArrowLeft") showImage(currentImageIndex - 1);
    if (event.key === "ArrowRight") showImage(currentImageIndex + 1);
  };
  return (
    <div
      className={styles.carousel}
      data-testid={testId}
      role="group"
      aria-roledescription="carousel"
      tabIndex={hasControls ? 0 : undefined}
      onKeyDown={hasControls ? showImageForKey : undefined}
    >
      <div className={styles.carouselStage}>
        <MediaImageTile
          key={`${currentImageIndex}:${currentImage.src}`}
          testId={`${testId}-tile-${currentImageIndex}`}
          image={currentImage}
          aspectRatio={aspectRatio}
          isEager
          onOpen={buildOpenImageHandler(images, currentImageIndex, isEmailRender)}
          onLoadFailed={() => onImageLoadFailed(currentImageIndex)}
        />
        {hasControls ? (
          <>
            <CarouselStepButton
              label={translateText("Previous picture")}
              icon="arrowLeft"
              className={styles.carouselButtonPrevious}
              isDisabled={currentImageIndex === 0}
              onStep={() => showImage(currentImageIndex - 1)}
              testId={`${testId}-previous`}
            />
            <CarouselStepButton
              label={translateText("Next picture")}
              icon="arrowRight"
              className={styles.carouselButtonNext}
              isDisabled={currentImageIndex === lastImageIndex}
              onStep={() => showImage(currentImageIndex + 1)}
              testId={`${testId}-next`}
            />
            <span className={styles.carouselPosition} aria-live="polite" data-testid={`${testId}-position`}>
              <Text role="caption" color="var(--agents-fg-on-dark)" translate={false}>
                {`${currentImageIndex + 1} / ${images.length}`}
              </Text>
            </span>
          </>
        ) : null}
      </div>
      <MediaCaption title={currentImage.title} caption={currentImage.caption} />
    </div>
  );
};

/**
 * The `ImageGroup` node (`ImageGroupComposedViewNode.php`): a gallery as a grid, a carousel or a
 * magazine; every picture opens the component library's lightbox over the whole gallery at its place. The pictures are capped at the
 * schema's `maxRepeatRows`; an entry without an http(s) / data:image `src` or an `alt` is dropped. It reports `failed`
 * for dropped, capped or unloadable pictures (the rest still shows), `empty` for a gallery with no picture (one muted
 * line), and nothing while its bound pictures are pending (the renderer reports the pending dataset; skeleton tiles).
 */
const ImageGroupNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const maxRepeatRows = composedNodeSchema.budgets.maxRepeatRows;
  const { images, droppedCount, cappedCount } = useMemo(
    () => readMediaImageList(nodeProps.images, maxRepeatRows),
    [nodeProps.images, maxRepeatRows],
  );
  const layout = readImageGroupLayout(nodeProps.layout);
  const isCompact = sizeClass === "compact";
  const [failedImageIndexes, setFailedImageIndexes] = useState<number[]>([]);
  const { reportNodeStatus } = runtime;
  const imageSourcesKey = images.map((image) => image.src).join("\n");

  useEffect(() => {
    setFailedImageIndexes([]);
  }, [imageSourcesKey]);

  useEffect(() => {
    if (isPending) return;
    if (droppedCount > 0) {
      reportNodeStatus(
        "failed",
        "INVALID_PROP",
        `${droppedCount} of ${droppedCount + images.length + cappedCount} pictures dropped: each needs an http(s) or data:image src and an alt text`,
      );
      return;
    }
    if (cappedCount > 0) {
      reportNodeStatus("failed", "BUDGET_EXCEEDED", `ImageGroup shows the first ${maxRepeatRows} pictures only`);
      return;
    }
    if (images.length === 0) {
      reportNodeStatus("empty", "NO_DATA", "the ImageGroup has no pictures");
      return;
    }
    if (failedImageIndexes.length > 0) {
      reportNodeStatus(
        "failed",
        "RENDER_FAILED",
        `${failedImageIndexes.length} of ${images.length} pictures did not load`,
      );
      return;
    }
    reportNodeStatus("mounted");
  }, [isPending, droppedCount, cappedCount, images.length, failedImageIndexes.length, maxRepeatRows, reportNodeStatus]);

  const markImageLoadFailed = (imageIndex: number) =>
    setFailedImageIndexes((currentIndexes) =>
      currentIndexes.includes(imageIndex) ? currentIndexes : [...currentIndexes, imageIndex],
    );

  if (isPending) {
    return (
      <div
        className={classNames(styles.grid, isCompact && styles.gridCompact)}
        aria-busy="true"
        data-testid={`${testId}-skeleton`}
      >
        {Array.from({ length: isCompact ? COMPACT_MAX_VISIBLE_TILES : PENDING_TILE_COUNT }, (unused, tileIndex) => (
          <span key={tileIndex} className={classNames(styles.pendingTile, isCompact && styles.pendingTileCompact)}>
            <Skeleton className={styles.pendingSkeleton} />
          </span>
        ))}
      </div>
    );
  }
  if (images.length === 0) {
    return (
      <Text role="help" color="var(--agents-fg-light)" testId={`${testId}-empty`}>
        {"No pictures to show."}
      </Text>
    );
  }
  const layoutProps: ImageGroupLayoutProps = {
    images,
    aspectRatio: readAspectRatio(nodeProps.aspectRatio, layout === "magazine" ? "1:1" : "4:3"),
    isEmailRender: runtime.isEmailRender,
    onImageLoadFailed: markImageLoadFailed,
    testId,
  };
  // A compact slot has no room for a carousel or a hero: every layout becomes the thumbnail strip there.
  if (isCompact) {
    return <ImageGroupGrid {...layoutProps} aspectRatio="4:3" maxVisibleTiles={COMPACT_MAX_VISIBLE_TILES} isCompact />;
  }
  if (layout === "carousel") {
    return <ImageGroupCarousel {...layoutProps} aspectRatio={readAspectRatio(nodeProps.aspectRatio, "16:9")} />;
  }
  if (layout === "magazine") {
    return <ImageGroupMagazine {...layoutProps} heroAspectRatio="16:9" />;
  }
  return (
    <ImageGroupGrid
      {...layoutProps}
      maxVisibleTiles={readPositiveCount(nodeProps.maxVisible, DEFAULT_MAX_VISIBLE_TILES)}
      isCompact={false}
    />
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "ImageGroup",
  component: ImageGroupNode,
};
