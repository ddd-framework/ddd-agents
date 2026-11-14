import { Icon, InteractiveSurface } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { ReactNode, useCallback, useEffect, useRef, useState } from "react";
import { translateText } from "../../../provider/environment";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readBooleanProp, readEnumProp, readTextProp } from "./layout-props";
import styles from "./Carousel.module.scss";

const VISIBLE_ITEM_COUNTS = [1, 2, 3, 4] as const;

const VISIBLE_CLASS_NAMES: Record<(typeof VISIBLE_ITEM_COUNTS)[number], string> = {
  1: styles.visible1,
  2: styles.visible2,
  3: styles.visible3,
  4: styles.visible4,
};

const SKELETON_SLIDE_COUNT = 3;

/** Whether the track can scroll further back / forward (a pixel of rounding counts as the end). */
const readScrollEdges = (track: HTMLDivElement) => ({
  canScrollBack: track.scrollLeft > 1,
  canScrollForward: track.scrollLeft + track.clientWidth < track.scrollWidth - 1,
});

/**
 * `Carousel` (`CarouselComposedViewNode.php`): slides in one horizontal row the owner pages through —
 * by swipe, by the keyboard on the focused track, or by the previous / next buttons, which show only while there is
 * something to scroll to. Each child node is a slide; with `items` the children are one slide's template rendered per
 * item (`$item` bound; the renderer caps it at `maxRepeatRows`). The e-mail capture shows the first slides without
 * controls. No slides → `empty`; `items` still waiting for its data → skeleton slides.
 */
const Carousel = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, renderSlotForItem, runtime, isPending, testId } = props;
  const trackRef = useRef<HTMLDivElement>(null);
  const [scrollEdges, setScrollEdges] = useState({ canScrollBack: false, canScrollForward: false });
  const visibleItemCount = readEnumProp(nodeProps.visibleItems, VISIBLE_ITEM_COUNTS, 2);
  const isShowingArrows = readBooleanProp(nodeProps.showArrows, true) && !runtime.isEmailRender;
  const carouselLabel = readTextProp(nodeProps.label);
  // With `items` written, the slides come from the list only — a list that did not resolve shows no slides.
  const isRepeatingItems = node.props.items !== undefined;
  const items = Array.isArray(nodeProps.items) ? nodeProps.items : [];
  const isWaitingForItems = isRepeatingItems && isPending && !Array.isArray(nodeProps.items);

  // One slide per item (the template's nodes together), or one slide per child node when there are no items.
  const slides: ReactNode[][] = isWaitingForItems
    ? []
    : isRepeatingItems
      ? items
          .map((item, itemIndex) => renderSlotForItem("children", item, itemIndex))
          .filter((slide) => slide.length > 0)
      : renderSlotForItem("children", null, 0).map((slideNode) => [slideNode]);
  const slideCount = slides.length;
  const { reportNodeStatus } = runtime;

  useEffect(() => {
    if (isWaitingForItems) reportNodeStatus("pending");
    else if (slideCount > 0) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Carousel has no slides to show");
  }, [isWaitingForItems, slideCount, reportNodeStatus]);

  const updateScrollEdges = useCallback(() => {
    if (trackRef.current) setScrollEdges(readScrollEdges(trackRef.current));
  }, []);

  useEffect(() => {
    const track = trackRef.current;
    if (!track) return undefined;
    updateScrollEdges();
    track.addEventListener("scroll", updateScrollEdges, { passive: true });
    const resizeObserver = typeof ResizeObserver === "undefined" ? undefined : new ResizeObserver(updateScrollEdges);
    resizeObserver?.observe(track);
    return () => {
      track.removeEventListener("scroll", updateScrollEdges);
      resizeObserver?.disconnect();
    };
  }, [updateScrollEdges, slideCount]);

  const scrollBySlide = (direction: 1 | -1) => {
    const track = trackRef.current;
    if (!track) return;
    const firstSlide = track.firstElementChild as HTMLElement | null;
    const slideStep = firstSlide ? firstSlide.getBoundingClientRect().width : track.clientWidth;
    const prefersReducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
    track.scrollBy?.({ left: direction * slideStep, behavior: prefersReducedMotion ? "auto" : "smooth" });
  };

  if (isWaitingForItems) {
    return (
      <div
        className={classNames(styles.carousel, VISIBLE_CLASS_NAMES[visibleItemCount])}
        aria-busy="true"
        data-testid={`${testId}-skeleton`}
      >
        <div className={classNames(styles.track, styles.frozenTrack)}>
          {Array.from({ length: SKELETON_SLIDE_COUNT }, (unused, skeletonIndex) => (
            <div key={skeletonIndex} className={styles.slide}>
              <div className={styles.skeletonSlide}>
                <SkeletonLine width="50%" height={12} />
                <SkeletonLine width="70%" height={24} />
                <SkeletonLine width="40%" height={10} />
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  }
  if (slideCount === 0) return null;

  return (
    <div className={classNames(styles.carousel, VISIBLE_CLASS_NAMES[visibleItemCount])} data-testid={testId}>
      <div className={styles.viewport}>
        <div
          ref={trackRef}
          data-testid={`${testId}-track`}
          className={classNames(styles.track, runtime.isEmailRender && styles.frozenTrack)}
          role="group"
          aria-roledescription={translateText("carousel")}
          aria-label={carouselLabel}
          tabIndex={runtime.isEmailRender ? undefined : 0}
        >
          {slides.map((slideNodes, slideIndex) => (
            <div
              key={slideIndex}
              className={styles.slide}
              data-testid={`${testId}-slide-${slideIndex}`}
              role="group"
              aria-roledescription={translateText("slide")}
              aria-label={
                translateText("%position% of %count%", { position: slideIndex + 1, count: slideCount })
              }
            >
              {slideNodes}
            </div>
          ))}
        </div>
        {isShowingArrows && scrollEdges.canScrollBack ? (
          <InteractiveSurface hoverable className={classNames(styles.arrow, styles.arrowPrevious)}>
            <button
              type="button"
              className={styles.arrowButton}
              data-testid={`${testId}-previous`}
              aria-label={translateText("Show the previous slides")}
              onClick={() => scrollBySlide(-1)}
            >
              <Icon name="arrowLeft" />
            </button>
          </InteractiveSurface>
        ) : null}
        {isShowingArrows && scrollEdges.canScrollForward ? (
          <InteractiveSurface hoverable className={classNames(styles.arrow, styles.arrowNext)}>
            <button
              type="button"
              className={styles.arrowButton}
              data-testid={`${testId}-next`}
              aria-label={translateText("Show the next slides")}
              onClick={() => scrollBySlide(1)}
            >
              <Icon name="arrowRight" />
            </button>
          </InteractiveSurface>
        ) : null}
      </div>
    </div>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Carousel", component: Carousel };
