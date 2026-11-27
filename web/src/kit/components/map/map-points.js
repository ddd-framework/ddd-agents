import { readOptionalComposedViewTone, } from "../../composed-view-tones";
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
const isPlainObject = (value) => typeof value === "object" && value !== null && !Array.isArray(value);
const readFirst = (record, keys) => {
    for (const key of keys) {
        if (Object.prototype.hasOwnProperty.call(record, key) && record[key] !== undefined && record[key] !== null) {
            return record[key];
        }
    }
    return undefined;
};
const readText = (record, keys) => {
    const value = readFirst(record, keys);
    if (typeof value === "number" && Number.isFinite(value))
        return String(value);
    if (typeof value !== "string")
        return undefined;
    const trimmed = value.trim();
    return trimmed === "" ? undefined : trimmed;
};
const readNumber = (value) => {
    if (typeof value === "number")
        return Number.isFinite(value) ? value : undefined;
    if (typeof value === "string" && value.trim() !== "") {
        const parsed = Number(value.trim().replace(",", "."));
        return Number.isFinite(parsed) ? parsed : undefined;
    }
    return undefined;
};
/** A coordinate pair inside the valid range, or undefined. Also takes `{position: {lat, lng}}` / `{location: …}`. */
export const readLatLng = (record) => {
    const nested = readFirst(record, ["position", "location", "coordinates"]);
    const source = isPlainObject(nested) ? nested : record;
    const lat = readNumber(readFirst(source, LAT_KEYS));
    const lng = readNumber(readFirst(source, LNG_KEYS));
    if (lat === undefined || lng === undefined)
        return undefined;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180)
        return undefined;
    return { lat, lng };
};
/** Only http(s) and inline `data:image/` URLs reach an `<img src>`. */
const readImageUrl = (record) => {
    const value = readText(record, IMAGE_KEYS);
    if (!value)
        return undefined;
    return /^https?:\/\//i.test(value) || /^data:image\//i.test(value) ? value : undefined;
};
/** The action name: a plain string, or the event form `{"action": "<name>"}`. */
const readActionName = (record) => {
    const value = readFirst(record, ACTION_KEYS);
    if (typeof value === "string" && value.trim() !== "")
        return value.trim();
    if (isPlainObject(value) && typeof value.action === "string" && value.action.trim() !== "")
        return value.action.trim();
    return undefined;
};
const readDetailTone = (record) => {
    return readOptionalComposedViewTone(readText(record, ["detailTone", "statusTone", "tone"]));
};
const readRating = (record) => {
    const rating = readNumber(readFirst(record, ["rating", "stars", "score"]));
    if (rating === undefined || rating < 0 || rating > 5)
        return undefined;
    return Math.round(rating * 10) / 10;
};
/**
 * One point. `fallbackActionName` is the Map's `pointAction`: the action a point without its own `action` runs — over
 * points bound to a resolved `$from` list a row-bound `openEntity` action opens each row's `entity`, since such a row carries no `action` key.
 */
const readPoint = (pointValue, pointIndex, fallbackActionName) => {
    if (!isPlainObject(pointValue))
        return undefined;
    const position = readLatLng(pointValue);
    if (!position)
        return undefined;
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
const readHeight = (value) => {
    const height = readNumber(value);
    if (height === undefined)
        return MAP_DEFAULT_HEIGHT_PX;
    return Math.min(MAP_MAX_HEIGHT_PX, Math.max(MAP_MIN_HEIGHT_PX, Math.round(height)));
};
const readRoutePath = (value, maxVertices) => {
    if (!Array.isArray(value))
        return [];
    const routePath = [];
    for (const pathValue of value.slice(0, maxVertices)) {
        const position = isPlainObject(pathValue) ? readLatLng(pathValue) : undefined;
        if (position)
            routePath.push(position);
    }
    return routePath;
};
const readTrimmedString = (value) => typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;
/**
 * The map view of a `Map` node's resolved props. `maxPoints` is the schema's `maxRepeatRows`; `maxRouteVertices` the
 * collection cap (a real road path from a tool is long).
 */
export const readMapView = (props, maxPoints, maxRouteVertices) => {
    const pointValues = Array.isArray(props.points) ? props.points : [];
    const cappedPointCount = Math.max(0, pointValues.length - maxPoints);
    const points = [];
    let droppedPointCount = 0;
    const pointActionName = readTrimmedString(props.pointAction);
    pointValues.slice(0, maxPoints).forEach((pointValue, pointIndex) => {
        const point = readPoint(pointValue, pointIndex, pointActionName);
        if (point)
            points.push(point);
        else
            droppedPointCount += 1;
    });
    const showRoute = props.route === true;
    const routePath = readRoutePath(props.routePath, maxRouteVertices);
    let routeLine = [];
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
export const formatMapRating = (rating, languageCode) => {
    try {
        return new Intl.NumberFormat(languageCode || undefined, {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        }).format(rating);
    }
    catch {
        return rating.toFixed(1);
    }
};
