import { Icon, InteractiveSurface, Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { useState } from "react";
import { COMPOSED_VIEW_TONE_TEXT_COLOR } from "../../composed-view-tones";
import { formatMapRating, type MapPoint } from "./map-points";
import styles from "./Map.module.scss";

export interface MapPointCardProps {
  point: MapPoint;
  /** `carousel`: a card along the map's bottom edge (inline); `row`: a row of the full-screen list. */
  variant: "carousel" | "row";
  isSelected: boolean;
  languageCode?: string;
  /** 1-based stop number on a route. */
  routeStopNumber?: number;
  /** Undefined → a static card (the e-mail capture). */
  onClick?: () => void;
}

/**
 * One point as a card: the thumbnail, the label, the rating with its category, and the
 * status line. Clickable through the GlobalStateComponent hover layer around a native button; selected = the inner CTA
 * ring. A point without an image shows the pin glyph on a flat tile, never an empty box.
 */
export const MapPointCard = (props: MapPointCardProps) => {
  const { point, variant, isSelected, languageCode, routeStopNumber, onClick } = props;
  const [isImageBroken, setIsImageBroken] = useState(false);
  const showImage = point.image !== undefined && !isImageBroken;

  const ratingLine = [
    point.rating !== undefined ? formatMapRating(point.rating, languageCode) : undefined,
    point.subtitle,
  ].filter((part): part is string => part !== undefined);

  const content = (
    <>
      <span className={styles.cardThumbnail} aria-hidden="true">
        {showImage ? (
          <img src={point.image} alt="" loading="lazy" onError={() => setIsImageBroken(true)} />
        ) : routeStopNumber !== undefined ? (
          <Text role="body" weight="bold" translate={false}>
            {String(routeStopNumber)}
          </Text>
        ) : (
          <Icon className={styles.cardThumbnailIcon} name="pinFilled" />
        )}
      </span>
      <span className={styles.cardText}>
        {point.label ? (
          <Text role="body" weight="bold" translate={false}>
            {escapePlainTextForText(point.label)}
          </Text>
        ) : null}
        {ratingLine.length > 0 ? (
          <span className={styles.cardMeta}>
            {point.rating !== undefined ? (
              <Icon className={styles.cardStar} size="small" name="starActive" />
            ) : null}
            <Text role="caption" color="var(--agents-fg-light)" translate={false}>
              {escapePlainTextForText(ratingLine.join(" · "))}
            </Text>
          </span>
        ) : null}
        {point.detail ? (
          <Text
            role="caption"
            weight={point.detailTone ? "medium" : undefined}
            color={point.detailTone ? COMPOSED_VIEW_TONE_TEXT_COLOR[point.detailTone] : "var(--agents-fg-light)"}
            translate={false}
          >
            {escapePlainTextForText(point.detail)}
          </Text>
        ) : null}
      </span>
    </>
  );

  const cardClassName = classNames(
    styles.card,
    variant === "carousel" ? styles.cardCarousel : styles.cardRow,
    isSelected && styles.cardSelected,
  );
  if (!onClick) {
    return (
      <div className={cardClassName} data-point-key={point.key}>
        <span className={styles.cardInner}>{content}</span>
      </div>
    );
  }
  return (
    <InteractiveSurface hoverable className={cardClassName}>
      <button
        type="button"
        className={classNames(styles.cardInner, styles.cardButton)}
        onClick={onClick}
        aria-pressed={isSelected}
        data-point-key={point.key}
        data-testid="map-point-card"
      >
        {content}
      </button>
    </InteractiveSurface>
  );
};
