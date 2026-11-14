import type { ConversationWidgetEntry } from "../renderer/conversationWidgetRegistry";
import { ComposedViewConversationWidget } from "./ComposedViewConversationWidget";

/**
 * The composable widget kit: a component tree the model composes; deliberately UNGATED — the data
 * it shows carries its own gates (the tools and data sources behind its bindings, an embedded widget's own feature
 * gate). It opens the `entityDetail` canvas (an `openEntity` action) and the `mapFullScreen` canvas (a `Map` node's
 * full-screen control). A canvas an embedded widget opens on a click is that widget's own: the embedded widget lists it
 * in its registry entry, and `findWidgetFencesInMarkdown` reads every embedded widget as a fence of its own, so the
 * reload restore finds it there.
 */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: "ComposedView",
  component: ComposedViewConversationWidget,
  opensCanvases: ["entityDetail", "mapFullScreen"],
};
