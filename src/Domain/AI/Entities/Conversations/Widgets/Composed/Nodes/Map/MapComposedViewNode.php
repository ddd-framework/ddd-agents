<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Map;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTone;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * An interactive map with labelled points (ChatGPT's `MapWidgetV2` / `BusinessLocationMap`):
 * one marker per point (its label, a rating badge when the point has one), the view fitted to all
 * points, a carousel of the points along the bottom edge, an optional route line through the points in their order,
 * and an expand control that opens the points in the `mapFullScreen` canvas (map + list). A point whose `action` names
 * an `openEntity` action of the tree opens that entity's detail panel on a click on its marker or its carousel entry; `pointAction` names
 * the action every point without its own runs — a row-bound `openEntity` action (`ref: {"$item":"entityRef"}`) over
 * `points` bound to a resolved `$from` list opens each row's own entity.
 *
 * A point is `{"label", "lat", "lng", "rating"?, "subtitle"?, "detail"?, "detailTone"?, "image"?, "action"?}` (`detailTone` one of
 * {@see ComposedViewTone::ACCEPTED_WORDS}) —
 * coordinates are required (the frontend does not geocode an address; a point without them is dropped and reported).
 * The frontend component is `components/map/Map.composed.tsx`; heavy, so only a slot that accepts `heavy` takes it.
 */
#[ComposedViewNodeProp(name: 'points', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, description: 'the places, each {"label","lat","lng"} with optional "rating" (0–5), "subtitle" (category), "detail" (a status line such as opening state) with "detailTone" (a tone of the kit: success for open, danger for closed, warning for closing soon; also neutral, muted, info), "image" (an http(s) photo URL) and "action" (the name of an openEntity action of this tree)')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'what the points are (e.g. "Cafés in Münster"), the heading of the full-screen list')]
#[ComposedViewNodeProp(name: 'route', types: [ComposedViewNodeProp::TYPE_BOOLEAN], description: 'draw a route line through the points in their order and number the markers')]
#[ComposedViewNodeProp(name: 'routePath', types: [ComposedViewNodeProp::TYPE_ARRAY], description: 'the route line as [{"lat","lng"}, …] when a tool returned the real path; without it the line joins the points directly')]
#[ComposedViewNodeProp(name: 'routeLabel', types: [ComposedViewNodeProp::TYPE_STRING], description: 'one short line on the route, e.g. its duration or length, shown on the map')]
#[ComposedViewNodeProp(name: 'carousel', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ['auto', 'hidden'], description: 'the carousel of the points along the bottom edge: auto (shown, default) or hidden')]
#[ComposedViewNodeProp(name: 'minHeight', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the map height in CSS pixels (240–560, default 330)')]
#[ComposedViewNodeProp(name: 'pointAction', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'the name of the action a point without its own "action" runs — over $from points a row-bound openEntity action ({"type":"openEntity","ref":{"$item":"entityRef"}}) opens each row\'s entity')]
class MapComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Map';

    public const array CATEGORIES = [ComposedViewNodeCategory::HEAVY];

    public const string SUMMARY = 'an interactive map: labelled points with rating badges, a carousel of the points, an optional route line, full-screen expand';
}
