import { Icon, InteractiveSurface } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { useCallback, useEffect, useRef, useState } from "react";
import { translateText } from "../../../provider/environment";
import type { MapPoint } from "./map-points";
import { MapPointCard } from "./MapPointCard";
import styles from "./Map.module.scss";

export interface MapPointCarouselProps {
  points: MapPoint[];
  selectedPointKey?: string;
  languageCode?: string;
  /** Whether the points are the stops of a route — the cards then show their stop number. */
  isRoute: boolean;
  /** Undefined → static cards and no paging buttons (the e-mail capture). */
  onPointSelect?: (point: MapPoint) => void;
}

/** Whether the track can scroll further back / forward (a pixel of rounding counts as the end). */
const readScrollEdges = (track: HTMLDivElement) => ({
  canScrollBack: track.scrollLeft > 1,
  canScrollForward: track.scrollLeft + track.clientWidth < track.scrollWidth - 1,
});

const prefersReducedMotion = (): boolean =>
  typeof window !== "undefined" && (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false);

/**
 * The point cards along the map's bottom edge: one horizontal, snapping row the owner pages through
 * by swipe, trackpad or the previous / next buttons, which show only while there is a card to scroll to — without
 * them a mouse could not reach the cards past the second one (the row hides its scrollbar so the map stays clean). A
 * selected point (a marker click) scrolls its card into view.
 */
export const MapPointCarousel = (props: MapPointCarouselProps) => {
  const { points, selectedPointKey, languageCode, isRoute, onPointSelect } = props;
  const trackRef = useRef<HTMLDivElement>(null);
  const [scrollEdges, setScrollEdges] = useState({ canScrollBack: false, canScrollForward: false });
  const isPageable = onPointSelect !== undefined;

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
  }, [updateScrollEdges, points.length]);

  // A marker click selects a point whose card may sit outside the row's visible part: bring it in.
  useEffect(() => {
    const track = trackRef.current;
    if (!track || selectedPointKey === undefined) return;
    const selectedCard = Array.from(track.querySelectorAll<HTMLElement>("[data-point-key]")).find(
      (cardElement) => cardElement.dataset.pointKey === selectedPointKey,
    );
    if (!selectedCard) return;
    const trackBox = track.getBoundingClientRect();
    const cardBox = selectedCard.getBoundingClientRect();
    if (cardBox.left >= trackBox.left && cardBox.right <= trackBox.right) return;
    track.scrollBy?.({
      left: cardBox.left < trackBox.left ? cardBox.left - trackBox.left : cardBox.right - trackBox.right,
      behavior: prefersReducedMotion() ? "auto" : "smooth",
    });
  }, [selectedPointKey]);

  const scrollByCard = (direction: 1 | -1) => {
    const track = trackRef.current;
    if (!track) return;
    const firstCard = track.firstElementChild as HTMLElement | null;
    const cardStep = firstCard ? firstCard.getBoundingClientRect().width : track.clientWidth;
    track.scrollBy?.({ left: direction * cardStep, behavior: prefersReducedMotion() ? "auto" : "smooth" });
  };

  return (
    <div className={styles.carouselViewport}>
      <div ref={trackRef} className={styles.carousel} role="list" data-testid="map-carousel">
        {points.map((point, pointIndex) => (
          <div
            role="listitem"
            key={point.key}
            className={styles.carouselItem}
            data-testid={`map-carousel-item-${point.key}`}
          >
            <MapPointCard
              point={point}
              variant="carousel"
              isSelected={point.key === selectedPointKey}
              languageCode={languageCode}
              routeStopNumber={isRoute ? pointIndex + 1 : undefined}
              onClick={onPointSelect ? () => onPointSelect(point) : undefined}
            />
          </div>
        ))}
      </div>
      {isPageable && scrollEdges.canScrollBack ? (
        <InteractiveSurface hoverable className={classNames(styles.carouselArrow, styles.carouselArrowPrevious)}>
          <button
            type="button"
            className={styles.carouselArrowButton}
            aria-label={translateText("Show the previous slides")}
            onClick={() => scrollByCard(-1)}
            data-testid="map-carousel-previous"
          >
            <Icon name="arrowLeft" />
          </button>
        </InteractiveSurface>
      ) : null}
      {isPageable && scrollEdges.canScrollForward ? (
        <InteractiveSurface hoverable className={classNames(styles.carouselArrow, styles.carouselArrowNext)}>
          <button
            type="button"
            className={styles.carouselArrowButton}
            aria-label={translateText("Show the next slides")}
            onClick={() => scrollByCard(1)}
            data-testid="map-carousel-next"
          >
            <Icon name="arrowRight" />
          </button>
        </InteractiveSurface>
      ) : null}
    </div>
  );
};
