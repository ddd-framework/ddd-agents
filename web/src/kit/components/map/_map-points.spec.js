import { describe, expect, test } from "vitest";
import { readMapView } from "./map-points";
/** `readMapView`, the reader of a `Map` node's props: aliases, coordinates, the repeat budget and the route line. */
describe("readMapView", () => {
    test("reads aliases, drops invalid coordinates and caps at the repeat budget", () => {
        const mapView = readMapView({
            points: [
                { name: "A", latitude: "51,96", lon: 7.62, stars: 4.26, category: "Café", imageUrl: "javascript:alert(1)" },
                { label: "B", lat: 95, lng: 7 },
                { label: "C", position: { lat: 51.9, lng: 7.6 } },
                { label: "D", lat: 51.8, lng: 7.5 },
            ],
            carousel: "hidden",
            minHeight: 9000,
        }, 3, 100);
        expect(mapView.points.map((point) => point.label)).toEqual(["A", "C"]);
        expect(mapView.points[0]).toMatchObject({ lat: 51.96, lng: 7.62, rating: 4.3, subtitle: "Café", image: undefined });
        expect(mapView.droppedPointCount).toBe(1);
        expect(mapView.cappedPointCount).toBe(1);
        expect(mapView.showCarousel).toBe(false);
        expect(mapView.heightPx).toBe(560);
    });
    test("pointAction is the action of every point without its own", () => {
        const mapView = readMapView({
            points: [
                { label: "A", lat: 51.96, lng: 7.62 },
                { label: "B", lat: 51.95, lng: 7.61, action: "openB" },
            ],
            pointAction: "openRow",
        }, 10, 100);
        expect(mapView.points.map((point) => point.actionName)).toEqual(["openRow", "openB"]);
        expect(readMapView({ points: [{ label: "A", lat: 51.96, lng: 7.62 }] }, 10, 100).points[0].actionName).toBeUndefined();
    });
    test("a route joins the points unless a route path is given", () => {
        const points = [
            { label: "Münster", lat: 51.96, lng: 7.62 },
            { label: "Köln", lat: 50.94, lng: 6.96 },
        ];
        expect(readMapView({ points, route: true }, 10, 100).routeLine).toHaveLength(2);
        const routePath = [
            { lat: 51.96, lng: 7.62 },
            { lat: 51.5, lng: 7.4 },
            { lat: 50.94, lng: 6.96 },
        ];
        expect(readMapView({ points, route: true, routePath }, 10, 100).routeLine).toEqual(routePath);
        expect(readMapView({ points, routePath }, 10, 100).routeLine).toEqual([]);
    });
});
