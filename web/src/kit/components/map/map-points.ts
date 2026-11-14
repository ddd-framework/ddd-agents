import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import {
  type ComposedViewTone,
  readOptionalComposedViewTone,
} from "../../composed-view-tones";

/**
 * The `Map` node's props, read into one typed map view (`MapComposedViewNode.php`). The props arrive
 * with every binding resolved; the reader checked their JSON types, not the shape of a point, so this reading is
 * alias-tolerant (a model that writes `name` / `latitude` / `lon` still gets its point), caps the points at the
 * repeat budget and never throws: a point without valid coordinates is dropped and counted, so the component can
 * report it. Pure — no React, no Google SDK.
 */

export interface MapPoint {
  /** Stable within one map: the point's position in the written list. */
  key: string;
  label: string;
  lat: number;
  lng: number;
  /** 0–5, one decimal. */
  rating?: number;
  /** The category line ("Café", "Restaurant"). */
  subtitle?: string;
  /** A status line ("Closed", "Open until 18:00"). */
  detail?: string;
  /**
   * How the status line reads, a tone of the kit's list (`composed-view-tones.ts`): `success` (open), `danger`
   * (closed), `warning` (closes soon) — the older `positive` / `negative` read as `success` / `danger`; none = plain text.
   */
  detailTone?: MapPointDetailTone;
  /** An http(s) or data: image URL. */
  image?: string;
  /** The name of a tree action the point runs on click (an `openEntity` action opens the entity's detail panel). */
  actionName?: string;
  /**
   * The point as the props hand it — a row of a resolved `$from` list carries its own `entityRef` + `entity`. The click
   * runs the action in this row, so a row-bound `openEntity` action (`ref: {"$item":"entityRef"}`) opens its entity.
   */
  row: Record<string, unknown>;
}

export type MapPointDetailTone = ComposedViewTone;

export interface MapLatLng {
  lat: number;
  lng: number;
}

export interface MapView {
  title?: string;
  points: MapPoint[];
  showRoute: boolean;
  /** The route line: `routePath` when given, else the points in their order (only when `showRoute`). */
  routeLine: MapLatLng[];
  routeLabel?: string;
  showCarousel: boolean;
  heightPx: number;
  /** Points dropped for missing or invalid coordinates. */
  droppedPointCount: number;
  /** Points past the repeat budget, not shown. */
  cappedPointCount: number;
}

export const MAP_DEFAULT_HEIGHT_PX = 330;
export const MAP_MIN_HEIGHT_PX = 240;
export const MAP_MAX_HEIGHT_PX = 560;

const LABEL_KEYS = ["label", "name", "title"];
const LAT_KEYS = ["lat", "latitude"];
const LNG_KEYS = ["lng", "lon", "long", "longitude"];
const SUBTITLE_KEYS = ["subtitle", "category", "type"];
const DETAIL_KEYS = ["detail", "status", "caption"];
const IMAGE_KEYS = ["image", "imageUrl", "photo", "thumbnail"];
const ACTION_KEYS = ["action", "onClick", "onSelect"];

const isPlainObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

const readFirst = (record: Record<string, unknown>, keys: string[]): unknown => {
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(record, key) && record[key] !== undefined && record[key] !== null) {
      return record[key];
    }
  }
  return undefined;
};

const readText = (record: Record<string, unknown>, keys: string[]): string | undefined => {
  const value = readFirst(record, keys);
  if (typeof value === "number" && Number.isFinite(value)) return String(value);
  if (typeof value !== "string") return undefined;
  const trimmed = value.trim();
  return trimmed === "" ? undefined : trimmed;
};

const readNumber = (value: unknown): number | undefined => {
  if (typeof value === "number") return Number.isFinite(value) ? value : undefined;
  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number(value.trim().replace(",", "."));
    return Number.isFinite(parsed) ? parsed : undefined;
  }
  return undefined;
};

/** A coordinate pair inside the valid range, or undefined. Also takes `{position: {lat, lng}}` / `{location: …}`. */
export const readLatLng = (record: Record<string, unknown>): MapLatLng | undefined => {
  const nested = readFirst(record, ["position", "location", "coordinates"]);
  const source = isPlainObject(nested) ? nested : record;
  const lat = readNumber(readFirst(source, LAT_KEYS));
  const lng = readNumber(readFirst(source, LNG_KEYS));
  if (lat === undefined || lng === undefined) return undefined;
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return undefined;
  return { lat, lng };
};

/** Only http(s) and inline `data:image/` URLs reach an `<img src>`. */
const readImageUrl = (record: Record<string, unknown>): string | undefined => {
  const value = readText(record, IMAGE_KEYS);
  if (!value) return undefined;
  return /^https?:\/\//i.test(value) || /^data:image\//i.test(value) ? value : undefined;
};

/** The action name: a plain string, or the event form `{"action": "<name>"}`. */
const readActionName = (record: Record<string, unknown>): string | undefined => {
  const value = readFirst(record, ACTION_KEYS);
  if (typeof value === "string" && value.trim() !== "") return value.trim();
  if (isPlainObject(value) && typeof value.action === "string" && value.action.trim() !== "")
    return value.action.trim();
  return undefined;
};

const readDetailTone = (record: Record<string, unknown>): MapPointDetailTone | undefined => {
  return readOptionalComposedViewTone(readText(record, ["detailTone", "statusTone", "tone"]));
};

const readRating = (record: Record<string, unknown>): number | undefined => {
  const rating = readNumber(readFirst(record, ["rating", "stars", "score"]));
  if (rating === undefined || rating < 0 || rating > 5) return undefined;
  return Math.round(rating * 10) / 10;
};

/**
 * One point. `fallbackActionName` is the Map's `pointAction`: the action a point without its own `action` runs — over
 * points bound to a resolved `$from` list a row-bound `openEntity` action opens each row's `entity`, since such a row carries no `action` key.
 */
const readPoint = (pointValue: unknown, pointIndex: number, fallbackActionName?: string): MapPoint | undefined => {
  if (!isPlainObject(pointValue)) return undefined;
  const position = readLatLng(pointValue);
  if (!position) return undefined;
  return {
    key: `point-${pointIndex}`,
    label: readText(pointValue, LABEL_KEYS) ?? "",
    lat: position.lat,
    lng: position.lng,
    rating: readRating(pointValue),
    subtitle: readText(pointValue, SUBTITLE_KEYS),
    detail: readText(pointValue, DETAIL_KEYS),
    detailTone: readDetailTone(pointValue),
    image: readImageUrl(pointValue),
    actionName: readActionName(pointValue) ?? fallbackActionName,
    row: pointValue,
  };
};

const readHeight = (value: ExpressionValue | undefined): number => {
  const height = readNumber(value);
  if (height === undefined) return MAP_DEFAULT_HEIGHT_PX;
  return Math.min(MAP_MAX_HEIGHT_PX, Math.max(MAP_MIN_HEIGHT_PX, Math.round(height)));
};

const readRoutePath = (value: ExpressionValue | undefined, maxVertices: number): MapLatLng[] => {
  if (!Array.isArray(value)) return [];
  const routePath: MapLatLng[] = [];
  for (const pathValue of value.slice(0, maxVertices)) {
    const position = isPlainObject(pathValue) ? readLatLng(pathValue) : undefined;
    if (position) routePath.push(position);
  }
  return routePath;
};

const readTrimmedString = (value: ExpressionValue | undefined): string | undefined =>
  typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;

/**
 * The map view of a `Map` node's resolved props. `maxPoints` is the schema's `maxRepeatRows`; `maxRouteVertices` the
 * collection cap (a real road path from a tool is long).
 */
export const readMapView = (
  props: Record<string, ExpressionValue>,
  maxPoints: number,
  maxRouteVertices: number,
): MapView => {
  const pointValues = Array.isArray(props.points) ? props.points : [];
  const cappedPointCount = Math.max(0, pointValues.length - maxPoints);
  const points: MapPoint[] = [];
  let droppedPointCount = 0;
  const pointActionName = readTrimmedString(props.pointAction);
  pointValues.slice(0, maxPoints).forEach((pointValue, pointIndex) => {
    const point = readPoint(pointValue, pointIndex, pointActionName);
    if (point) points.push(point);
    else droppedPointCount += 1;
  });
  const showRoute = props.route === true;
  const routePath = readRoutePath(props.routePath, maxRouteVertices);
  let routeLine: MapLatLng[] = [];
  if (showRoute) {
    routeLine = routePath.length >= 2 ? routePath : points.map((point) => ({ lat: point.lat, lng: point.lng }));
  }
  return {
    title: readTrimmedString(props.title),
    points,
    showRoute,
    routeLine,
    routeLabel: showRoute ? readTrimmedString(props.routeLabel) : undefined,
    showCarousel: props.carousel !== "hidden" && points.length > 0,
    heightPx: readHeight(props.minHeight),
    droppedPointCount,
    cappedPointCount,
  };
};

/** The rating as the owner's locale writes it (one decimal: "4,7" in German, "4.7" in English). */
export const formatMapRating = (rating: number, languageCode?: string): string => {
  try {
    return new Intl.NumberFormat(languageCode || undefined, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }).format(rating);
  } catch {
    return rating.toFixed(1);
  }
};
