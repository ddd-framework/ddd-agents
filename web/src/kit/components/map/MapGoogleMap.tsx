import { useCallback, useContext, useEffect, useState } from "react";
import { Map as AgentsMap, MapMarker } from "../../../provider/components";
import { WidgetImageReadinessContext } from "../../../image-render/WidgetImageRenderContext";
import type { MapPoint, MapView } from "./map-points";
import { MapPointMarker } from "./MapPointMarker";
import styles from "./Map.module.scss";

export interface MapGoogleMapProps {
  mapView: MapView;
  selectedPointKey?: string;
  languageCode?: string;
  /** The Google SDK is loaded (`useGoogleMapApiLoader`, the one loader of the app). */
  isJsApiLoaded: boolean;
  isEmailRender: boolean;
  /** Undefined → markers are static (the e-mail capture, a replay without interaction). */
  onPointClick?: (point: MapPoint) => void;
  /** Extra bottom room kept free of points when fitting — the carousel lies over the map's bottom edge. */
  bottomOverlayPx: number;
  className?: string;
}

const FIT_PADDING_PX = 48;
const SINGLE_POINT_ZOOM = 15;
const ROUTE_STROKE_WEIGHT = 4;
const ROUTE_STROKE_OPACITY = 0.85;
/** Used only when the theme token cannot be read (a host without the theme stylesheet). */
const ROUTE_STROKE_FALLBACK = "#1f2329";

/** The route line's colour: the theme's foreground token, read once at draw time (the SDK takes a colour, not a var). */
const readRouteStrokeColor = (mapDiv: HTMLElement | null): string => {
  if (!mapDiv) return ROUTE_STROKE_FALLBACK;
  const tokenValue = getComputedStyle(mapDiv).getPropertyValue("--agents-fg").trim();
  return tokenValue || ROUTE_STROKE_FALLBACK;
};

/**
 * The Google map of a `Map` node: the environment's map (the app supplies the key and the map id), one marker per point, the route polyline when the node asks
 * for one, the view fitted to every point and the route, and a pan to the selected point. In the e-mail capture the
 * map has no controls and no gestures, and it reports `MAP_SDK_LOAD_FAILED` to the capture readiness on a load error.
 */
export const MapGoogleMap = (props: MapGoogleMapProps) => {
  const {
    mapView,
    selectedPointKey,
    languageCode,
    isJsApiLoaded,
    isEmailRender,
    onPointClick,
    bottomOverlayPx,
    className,
  } = props;
  const readiness = useContext(WidgetImageReadinessContext);
  const [map, setMap] = useState<google.maps.Map>();
  const [hasTilesLoaded, setHasTilesLoaded] = useState(false);
  const handleOnLoad = useCallback((loadedMap: google.maps.Map) => setMap(loadedMap), []);
  // The first frame's centre only — a centre prop that changed on every render would undo every pan and fit.
  const [initialCenter] = useState(() =>
    mapView.points[0] ? { lat: mapView.points[0].lat, lng: mapView.points[0].lng } : undefined,
  );

  // The view: every point and the whole route; one point alone gets a street-level zoom instead of a fit.
  const fitKey = JSON.stringify([mapView.points.map((point) => [point.lat, point.lng]), mapView.routeLine.length]);
  useEffect(() => {
    if (!map || typeof google === "undefined") return;
    const positions = [...mapView.points, ...mapView.routeLine];
    if (positions.length === 0) return;
    if (positions.length === 1) {
      map.setCenter({ lat: positions[0].lat, lng: positions[0].lng });
      map.setZoom(SINGLE_POINT_ZOOM);
      return;
    }
    const bounds = new google.maps.LatLngBounds();
    positions.forEach((position) => bounds.extend({ lat: position.lat, lng: position.lng }));
    map.fitBounds(bounds, {
      top: FIT_PADDING_PX,
      right: FIT_PADDING_PX,
      bottom: FIT_PADDING_PX + bottomOverlayPx,
      left: FIT_PADDING_PX,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [map, fitKey, bottomOverlayPx]);

  // The route line through the stops (or the tool's path), removed with the map or a new route.
  useEffect(() => {
    if (!map || typeof google === "undefined" || mapView.routeLine.length < 2) return;
    const routePolyline = new google.maps.Polyline({
      path: mapView.routeLine,
      map,
      strokeColor: readRouteStrokeColor(map.getDiv()),
      strokeWeight: ROUTE_STROKE_WEIGHT,
      strokeOpacity: ROUTE_STROKE_OPACITY,
      clickable: false,
    });
    return () => routePolyline.setMap(null);
  }, [map, mapView.routeLine]);

  // A click on a card or a marker brings its point into view.
  useEffect(() => {
    const selectedPoint = mapView.points.find((point) => point.key === selectedPointKey);
    if (map && selectedPoint) map.panTo({ lat: selectedPoint.lat, lng: selectedPoint.lng });
  }, [map, selectedPointKey, mapView.points]);

  // The e-mail capture waits for the tiles; a map that cannot render fails the capture instead of a blank image.
  useEffect(() => {
    if (!map || !isEmailRender) return;
    const tilesListener = map.addListener("tilesloaded", () => setHasTilesLoaded(true));
    return () => tilesListener.remove();
  }, [map, isEmailRender]);
  useEffect(() => {
    if (isEmailRender && isJsApiLoaded && mapView.points.length === 0) readiness?.fail("MAP_COORDINATES_MISSING");
  }, [isEmailRender, isJsApiLoaded, mapView.points.length, readiness]);

  const staticOptions: google.maps.MapOptions = isEmailRender
    ? {
        disableDefaultUI: true,
        zoomControl: false,
        fullscreenControl: false,
        mapTypeControl: false,
        keyboardShortcuts: false,
        gestureHandling: "none",
        clickableIcons: false,
      }
    : { fullscreenControl: false, clickableIcons: false };

  return (
    <div className={className} aria-busy={isEmailRender ? !hasTilesLoaded : undefined}>
      <AgentsMap
        testId="ComposedViewMap-GoogleMaps"
        isLoaded={isJsApiLoaded}
        languageCode={languageCode}
        onLoad={handleOnLoad}
        zoom={12}
        center={initialCenter}
        className={styles.mapContainer}
        options={{
          ...staticOptions,
          streetViewControl: false,
          mapTypeControlOptions: { mapTypeIds: [] },
        }}
      >
        {mapView.points.map((point, pointIndex) => (
          <MapMarker
            key={point.key}
            id={`composed-map-${point.key}`}
            isLoaded={isJsApiLoaded}
            position={{ lat: point.lat, lng: point.lng }}
            zIndex={point.key === selectedPointKey ? 20 : 10}
            title={point.label || undefined}
          >
            <MapPointMarker
              point={point}
              routeStopNumber={mapView.showRoute ? pointIndex + 1 : undefined}
              isSelected={point.key === selectedPointKey}
              languageCode={languageCode}
              onClick={onPointClick ? () => onPointClick(point) : undefined}
            />
          </MapMarker>
        ))}
      </AgentsMap>
    </div>
  );
};
