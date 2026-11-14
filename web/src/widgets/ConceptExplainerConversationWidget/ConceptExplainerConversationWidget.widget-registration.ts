import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { ConceptExplainerConversationWidget } from "./ConceptExplainerConversationWidget";

/**
 * The interactive explainer — deliberately UNGATED: the frame serves the explainer types an app
 * declares in several of its domains; a domain flag would hide every type for an owner missing that one domain, and
 * an explainer shows no data a feature flag protects. Opens no canvas.
 */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: "ConceptExplainer",
  component: ConceptExplainerConversationWidget,
};
