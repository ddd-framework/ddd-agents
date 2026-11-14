import { shortStringHash } from "../../../renderer/canvas/canvas-state";
import { findWidgetFencesInMarkdown } from "../../../renderer/canvas/find-widget-fences-in-markdown";
import {
  getComposedViewSubtreeNodes,
  parseComposedViewTree,
  type ComposedViewNode,
  type ComposedViewTree,
} from "../../../renderer/widget-tree/composed-view-tree";

/**
 * Which `Map` node a `mapFullScreen` canvas shows. A canvas is a pointer to its anchor message, so the
 * instance id carries only the message and a short hash of the node — its id and its
 * wire props — and the body finds the node again in the message's `ComposedView` fences. Two identical maps in one
 * message are one canvas; a map whose message no longer carries it opens nothing.
 *
 * Format `mapFullScreen:message:<messageId>:map-<hash>` — the `map-` part keeps it apart from the payload-hash form
 * (`payloadHashOfInstanceId`), which the shared canvas helpers would otherwise read as a fence hash.
 */

export const MAP_NODE_TYPE = "Map";
const COMPOSED_VIEW_WIDGET_NAME = "ComposedView";
const MAP_FULL_SCREEN_INSTANCE_ID = /^mapFullScreen:message:(\d+):map-([0-9a-z]+)$/;

/** The short hash of a map node: its id and its props as written (bindings unresolved), so it is stable across renders. */
export const buildMapNodeKey = (mapNode: ComposedViewNode): string =>
  shortStringHash(JSON.stringify({ id: mapNode.id, props: mapNode.props }));

export const canvasInstanceIdForMapFullScreen = (messageId: number, mapNode: ComposedViewNode): string =>
  `mapFullScreen:message:${messageId}:map-${buildMapNodeKey(mapNode)}`;

/** The map node key a `mapFullScreen` instance id carries, or undefined for another shape. */
export const readMapNodeKeyOfInstanceId = (instanceId: string): string | undefined =>
  MAP_FULL_SCREEN_INSTANCE_ID.exec(instanceId)?.[2];

export interface MapNodeInComposedView {
  /** The whole tree the map sits in — its state, data, computed values and actions resolve the map's bindings. */
  tree: ComposedViewTree;
  mapNode: ComposedViewNode;
}

/**
 * The `Map` node with this key among the `ComposedView` fences of a message, with its tree. Total: an unparsable
 * message or a missing map gives undefined, never a throw (a canvas body runs outside the message's error boundary).
 */
export const findMapNodeInMarkdown = (markdown: string, mapNodeKey: string): MapNodeInComposedView | undefined => {
  try {
    for (const fence of findWidgetFencesInMarkdown(markdown)) {
      if (fence.name !== COMPOSED_VIEW_WIDGET_NAME) continue;
      const tree = parseComposedViewTree(fence.payload);
      if (tree.view === null) continue;
      const mapNode = getComposedViewSubtreeNodes(tree.view).find(
        (node) => node.type === MAP_NODE_TYPE && buildMapNodeKey(node) === mapNodeKey,
      );
      if (mapNode) return { tree, mapNode };
    }
  } catch {
    return undefined;
  }
  return undefined;
};
