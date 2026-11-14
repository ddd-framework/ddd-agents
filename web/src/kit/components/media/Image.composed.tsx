import { Skeleton } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { useEffect, useMemo, useState } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { MediaCaption } from "./MediaCaption";
import { MediaImageTile } from "./MediaImageTile";
import { openMediaImagesLightbox, readAspectRatio, readImageFit, readMediaImage } from "./media-values";
import styles from "./Image.module.scss";

/**
 * The `Image` node (`ImageComposedViewNode.php`): one picture with its
 * title and caption under it and its source as a chip over it, lazily loaded, opening the component library's lightbox on click. In
 * a compact slot (a table cell) it is a 4:3 thumbnail without text. A picture whose `src` is no http(s) or data:image
 * address reports `failed` and renders nothing; a picture that does not load shows its alt text and reports `failed`.
 */
const ImageNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const image = useMemo(() => readMediaImage(nodeProps), [nodeProps]);
  const aspectRatio = readAspectRatio(nodeProps.aspectRatio, "4:3");
  const isCompact = sizeClass === "compact";
  const [hasLoadFailed, setHasLoadFailed] = useState(false);
  const { reportNodeStatus } = runtime;

  useEffect(() => {
    setHasLoadFailed(false);
  }, [image?.src]);

  useEffect(() => {
    if (isPending) return;
    if (!image) {
      reportNodeStatus("failed", "INVALID_PROP", "the Image has no http(s) or data:image src, or no alt text");
      return;
    }
    if (hasLoadFailed) {
      reportNodeStatus("failed", "RENDER_FAILED", `the picture "${image.alt}" did not load`);
      return;
    }
    reportNodeStatus("mounted");
  }, [isPending, image, hasLoadFailed, reportNodeStatus]);

  if (isPending) {
    return (
      <span
        className={classNames(styles.pendingFrame, isCompact && styles.pendingFrameCompact)}
        aria-busy="true"
        data-testid={`${testId}-skeleton`}
      >
        <Skeleton className={styles.pendingSkeleton} />
      </span>
    );
  }
  if (!image) return null;
  const openLightbox = runtime.isEmailRender ? undefined : () => openMediaImagesLightbox([image], 0);
  return (
    <figure className={classNames(styles.image, isCompact && styles.imageCompact)} data-testid={testId}>
      <MediaImageTile
        testId={`${testId}-tile`}
        image={image}
        aspectRatio={isCompact ? "4:3" : aspectRatio}
        fit={readImageFit(nodeProps.fit)}
        isEager={runtime.isEmailRender}
        isCompact={isCompact}
        onOpen={openLightbox}
        onLoadFailed={() => setHasLoadFailed(true)}
      />
      {isCompact ? null : <MediaCaption title={image.title} caption={image.caption} />}
    </figure>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Image", component: ImageNode };
