import { Icon, Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { translateText } from "../../../provider/environment";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { MapPoint, MapView } from "./map-points";
import type { MapPresentation } from "./map-presentation-context";
import { MapGoogleMap } from "./MapGoogleMap";
import { MapPointCard } from "./MapPointCard";
import { MapPointCarousel } from "./MapPointCarousel";
import styles from "./Map.module.scss";

export interface ComposedMapViewProps {
  mapView: MapView;
  presentation: MapPresentation;
  /** A bound `points` prop still waits for its data. */
  isPending: boolean;
  /** The Google SDK state from the app's one loader (`useGoogleMapApiLoader`). */
  isJsApiLoaded: boolean;
  hasLoadError: boolean;
  isEmailRender: boolean;
  languageCode?: string;
  selectedPointKey?: string;
  /** Undefined → points are static (the e-mail capture). */
  onPointSelect?: (point: MapPoint) => void;
  /** Undefined → no expand control (the e-mail capture, a reply still streaming, the full-screen canvas itself). */
  onExpand?: () => void;
  /** The `mapFullScreen` canvas shows this map now: the control closes it again. */
  isShownFullScreen?: boolean;
}

/** The bottom band the inline carousel covers, kept free of points when the map fits its view. */
const CAROUSEL_OVERLAY_PX = 112;

/**
 * The look of a `Map` node. Inline: the map in a rounded frame, the
 * route line's label top left, the expand control top right, the point cards along the bottom edge. Full screen (the
 * `mapFullScreen` canvas): the map filling the pane beside the list of every point. Loading is a frame-shaped
 * skeleton; no points renders nothing; a map that cannot load keeps its cards with one muted line in the frame —
 * the component reports each.
 */
export const ComposedMapView = (props: ComposedMapViewProps) => {
  const {
    mapView,
    presentation,
    isPending,
    isJsApiLoaded,
    hasLoadError,
    isEmailRender,
    languageCode,
    selectedPointKey,
    onPointSelect,
    onExpand,
    isShownFullScreen = false,
  } = props;
  const isFullScreen = presentation === "fullScreen";
  const frameStyle = isFullScreen ? undefined : { height: mapView.heightPx };

  if (isPending) {
    return (
      <div className={styles.frame} style={frameStyle} aria-busy="true" data-testid="map-skeleton">
        <SkeletonLine width="100%" height={mapView.heightPx} radius={0} />
      </div>
    );
  }
  // No points: nothing on screen (the prose answer stands); the node reports `empty` so the model knows.
  if (mapView.points.length === 0) return null;

  // Without the map the cards are the only place the points show, so they stay even when the node hid them.
  const showCarousel = mapView.showCarousel || hasLoadError;
  const routeStopNumberOf = (pointIndex: number) => (mapView.showRoute ? pointIndex + 1 : undefined);
  // A map that cannot load keeps its points: the frame shows one muted line where the map would be, the cards and the
  // full-screen list stay.
  const googleMap = hasLoadError ? (
    <div className={styles.mapUnavailable} data-testid="map-unavailable">
      <Text role="help" color="var(--agents-fg-light)">
        {"The map could not be loaded."}
      </Text>
    </div>
  ) : isJsApiLoaded ? (
    <MapGoogleMap
      className={styles.mapLayer}
      mapView={mapView}
      selectedPointKey={selectedPointKey}
      languageCode={languageCode}
      isJsApiLoaded={isJsApiLoaded}
      isEmailRender={isEmailRender}
      onPointClick={onPointSelect}
      bottomOverlayPx={!isFullScreen && showCarousel ? CAROUSEL_OVERLAY_PX : 0}
    />
  ) : (
    <div className={styles.mapLayer} aria-busy="true">
      <SkeletonLine width="100%" height={mapView.heightPx} radius={0} />
    </div>
  );
  const routeChip = mapView.routeLabel ? (
    <div className={styles.routeChip} data-testid="map-route-label">
      <Icon className={styles.routeChipIcon} size="small" name="clock" />
      <Text role="caption" weight="medium" translate={false}>
        {escapePlainTextForText(mapView.routeLabel)}
      </Text>
    </div>
  ) : null;

  if (isFullScreen) {
    return (
      <div className={styles.fullScreen} data-testid="map-full-screen">
        <div className={styles.fullScreenMap}>
          {googleMap}
          {routeChip}
        </div>
        <aside className={styles.fullScreenList}>
          <div className={styles.fullScreenHeader}>
            {mapView.title ? (
              <Text role="intro" weight="bold" translate={false}>
                {escapePlainTextForText(mapView.title)}
              </Text>
            ) : null}
            <Text
              role="caption"
              color="var(--agents-fg-light)"
              replacements={{ count: mapView.points.length }}
              context={mapView.points.length === 1 ? "one" : "other"}
            >
              {"%count% place"}
            </Text>
          </div>
          <div className={styles.fullScreenRows} role="list" data-testid="map-full-screen-list">
            {mapView.points.map((point, pointIndex) => (
              <div role="listitem" key={point.key} data-testid={`map-full-screen-row-${point.key}`}>
                <MapPointCard
                  point={point}
                  variant="row"
                  isSelected={point.key === selectedPointKey}
                  languageCode={languageCode}
                  routeStopNumber={routeStopNumberOf(pointIndex)}
                  onClick={onPointSelect ? () => onPointSelect(point) : undefined}
                />
              </div>
            ))}
          </div>
        </aside>
      </div>
    );
  }

  return (
    <div className={styles.frame} style={frameStyle} data-testid="map-inline">
      {googleMap}
      {routeChip}
      {onExpand ? (
        <button
          type="button"
          className={styles.expandButton}
          onClick={onExpand}
          aria-pressed={isShownFullScreen}
          aria-label={
            translateText(isShownFullScreen ? "Close the full-screen map" : "Open the map in full screen")
          }
          data-testid="map-expand"
        >
          <Icon name={isShownFullScreen ? "minimize" : "maximize"} />
        </button>
      ) : null}
      {showCarousel ? (
        <MapPointCarousel
          points={mapView.points}
          selectedPointKey={selectedPointKey}
          languageCode={languageCode}
          isRoute={mapView.showRoute}
          onPointSelect={onPointSelect}
        />
      ) : null}
    </div>
  );
};
