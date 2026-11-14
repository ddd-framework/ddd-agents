import { Button, Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { formatMapRating, type MapPoint } from "./map-points";
import styles from "./Map.module.scss";

export interface MapPointMarkerProps {
  point: MapPoint;
  /** 1-based, shown instead of the rating when the map draws a route (the stops in their order). */
  routeStopNumber?: number;
  isSelected: boolean;
  languageCode?: string;
  /** Undefined → a static pin (the e-mail capture): no button, nothing to focus. */
  onClick?: () => void;
}

/**
 * One point on the map: a small dark pill — the rating with a star, the stop number on a route, or a
 * plain dot — over the point's label with a halo so it reads on any map tile. The pill is a component-library muted `onDark`
 * Button; only the pill takes clicks, never the label.
 */
export const MapPointMarker = (props: MapPointMarkerProps) => {
  const { point, routeStopNumber, isSelected, languageCode, onClick } = props;
  const hasRating = point.rating !== undefined && routeStopNumber === undefined;
  let pillText = "•";
  if (routeStopNumber !== undefined) pillText = String(routeStopNumber);
  else if (point.rating !== undefined) pillText = formatMapRating(point.rating, languageCode);

  return (
    <div
      className={classNames(styles.marker, isSelected && styles.markerSelected)}
      data-testid="map-point-marker"
      data-point-key={point.key}
    >
      <span
        // The component library's Button takes no aria-label: the point's name goes onto its native button.
        ref={(node) => {
          if (point.label) node?.querySelector("button")?.setAttribute("aria-label", point.label);
        }}
        className={classNames(styles.markerPill, onClick && styles.markerPillInteractive)}
        inert={!onClick}
        aria-hidden={onClick ? undefined : "true"}
      >
        <Button
          variant="muted"
          size="small"
          rounded
          onDark
          pressed={onClick ? isSelected : undefined}
          icon={hasRating ? "starActive" : undefined}
          translate={false}
          onClick={onClick}
          testId={`map-point-marker-pill-${point.key}`}
        >
          {pillText}
        </Button>
      </span>
      {point.label ? (
        <span className={styles.markerLabel}>
          <Text role="caption" weight="medium" display="inline" translate={false}>
            {escapePlainTextForText(point.label)}
          </Text>
        </span>
      ) : null}
    </div>
  );
};
