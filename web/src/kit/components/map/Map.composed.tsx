import { useEffect, useMemo, useState } from "react";
import { useAgentsMapsLoader } from "../../../provider/AgentsProvider";
import { useConversationWidgetContext } from "../../../renderer/ConversationWidgetCallbacksContext";
import { messageAnchor } from "../../../renderer/canvas/canvas-state";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readMapView, type MapPoint, type MapView } from "./map-points";
import { useMapPresentation } from "./map-presentation-context";
import { canvasInstanceIdForMapFullScreen, MAP_NODE_TYPE } from "./map-full-screen-instance";
import { ComposedMapView } from "./ComposedMapView";
import { useGoogleMapsAuthFailure } from "./use-google-maps-auth-failure";

interface MapLoadState {
  isPending: boolean;
  isJsApiLoaded: boolean;
  hasLoadError: boolean;
  /** Google refused the key (`gm_authFailure`): the script loaded, the map cannot render. */
  hasAuthFailed: boolean;
}

/** What the block reports for this map: never a silent nothing. */
export const buildMapRenderReport = (mapView: MapView, loadState: MapLoadState): WidgetRenderReportEntry => {
  if (loadState.isPending) return { status: "pending" };
  if (loadState.hasLoadError || loadState.hasAuthFailed) {
    const reason = loadState.hasAuthFailed ? "Google refused the Maps key" : "the Google Maps script did not load";
    return { status: "failed", code: "RENDER_FAILED", detail: `${reason} — the points show without the map` };
  }
  if (mapView.points.length === 0 && mapView.droppedPointCount > 0) {
    return {
      status: "failed",
      code: "INVALID_PROP",
      detail: `none of the ${mapView.droppedPointCount} Map points has valid "lat"/"lng" coordinates — no map shown`,
    };
  }
  if (mapView.points.length === 0) {
    return { status: "empty", code: "NO_DATA", detail: "the Map has no points — no map shown" };
  }
  if (!loadState.isJsApiLoaded) return { status: "pending" };
  if (mapView.droppedPointCount > 0) {
    return {
      status: "failed",
      code: "INVALID_PROP",
      detail: `${mapView.droppedPointCount} Map point(s) without valid "lat"/"lng" coordinates are not on the map`,
    };
  }
  if (mapView.cappedPointCount > 0) {
    return {
      status: "failed",
      code: "BUDGET_EXCEEDED",
      detail: `the Map shows the first ${mapView.points.length} points only`,
    };
  }
  return { status: "mounted" };
};

/**
 * The `Map` node (`MapComposedViewNode.php`): labelled points with rating badges, the view fitted to
 * them, a card carousel, an optional route line, and an expand control that opens the `mapFullScreen` canvas (anchored
 * on the host message; the body finds this node again by its key). A marker or card click selects the point and runs
 * the point's `action` — an `openEntity` action opens the entity's detail panel. The Google SDK comes from the app's one
 * loader (`AgentsEnvironment.useMapsLoader`), the key and map id from the app, as every map widget of the chat.
 */
const MapNode = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime } = props;
  const presentation = useMapPresentation();
  const { openCanvas, closeCanvas, canvas } = useConversationWidgetContext();
  const { isLoaded, loadError, languageCode } = useAgentsMapsLoader();
  const hasAuthFailed = useGoogleMapsAuthFailure();
  const hasLoadError = loadError !== undefined || hasAuthFailed;
  const [selectedPointKey, setSelectedPointKey] = useState<string>();
  const { maxRepeatRows, maxCollectionItems } = composedNodeSchema.budgets;

  // The resolved props are a new object on every render; the view is rebuilt only when their content changes.
  const nodePropsKey = JSON.stringify(nodeProps);
  const mapView = useMemo(
    () => readMapView(nodeProps, maxRepeatRows, maxCollectionItems),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [nodePropsKey, maxRepeatRows, maxCollectionItems],
  );

  const report = buildMapRenderReport(mapView, {
    isPending,
    isJsApiLoaded: isLoaded,
    hasLoadError: loadError !== undefined,
    hasAuthFailed,
  });
  const { reportNodeStatus } = runtime;
  useEffect(() => {
    reportNodeStatus(report.status, report.code, report.detail);
  }, [reportNodeStatus, report.status, report.code, report.detail]);

  const selectPoint = (point: MapPoint) => {
    setSelectedPointKey(point.key);
    if (point.actionName) runtime.runEvent({ action: point.actionName }, undefined, point.row as ExpressionValue);
  };

  const numericMessageId = runtime.messageId === undefined ? Number.NaN : Number(runtime.messageId);
  const canExpand = presentation === "inline" && !runtime.isEmailRender && Number.isFinite(numericMessageId);
  const fullScreenInstanceId = canExpand ? canvasInstanceIdForMapFullScreen(numericMessageId, node) : undefined;
  const isShownFullScreen =
    fullScreenInstanceId !== undefined &&
    canvas?.canvasId === "mapFullScreen" &&
    canvas.instanceId === fullScreenInstanceId;
  // The control toggles: it opens this map full screen, and closes the canvas again while it shows this map.
  const toggleFullScreen = () => {
    if (fullScreenInstanceId === undefined) return;
    if (isShownFullScreen) {
      closeCanvas(true);
      return;
    }
    openCanvas({
      canvasId: "mapFullScreen",
      instanceId: fullScreenInstanceId,
      anchor: messageAnchor(numericMessageId),
      origin: "cardClick",
      justDelivered: false,
    });
  };

  return (
    <ComposedMapView
      mapView={mapView}
      presentation={presentation}
      isPending={isPending}
      isJsApiLoaded={isLoaded}
      hasLoadError={hasLoadError}
      isEmailRender={runtime.isEmailRender}
      languageCode={languageCode}
      selectedPointKey={selectedPointKey}
      onPointSelect={runtime.isEmailRender ? undefined : selectPoint}
      onExpand={canExpand ? toggleFullScreen : undefined}
      isShownFullScreen={isShownFullScreen}
    />
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: MAP_NODE_TYPE, component: MapNode };
