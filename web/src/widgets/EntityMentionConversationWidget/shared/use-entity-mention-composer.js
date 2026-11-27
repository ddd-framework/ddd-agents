import { useCallback } from "react";
import { useConversationWidgetContext } from "../../../renderer/ConversationWidgetCallbacksContext";
import { buildEntityMentionFields, ENTITY_MENTION_WIDGET_NAME, entityMentionKey, MAX_ENTITY_MENTIONS_PER_MESSAGE, normalizeEntityMentionFields, toEntityMentionWireFields, } from "./entity-mention";
/**
 * The opener side of the `EntityMention` composer widget: what the detail panel's "Ask about this"
 * button calls. Reads and writes the composer widgets of the chat's widget context; the composer strip renders them.
 */
export const useEntityMentionComposer = () => {
    const { composerWidgets, addComposerWidget, replaceComposerWidgetFields } = useConversationWidgetContext();
    const findMentionWidgets = useCallback(() => composerWidgets.filter((composerWidget) => composerWidget.widgetName === ENTITY_MENTION_WIDGET_NAME), [composerWidgets]);
    const isMentioned = useCallback((target) => {
        const mention = buildEntityMentionFields(target);
        if (mention === null)
            return false;
        return findMentionWidgets().some((composerWidget) => {
            const heldMention = normalizeEntityMentionFields(composerWidget.fields);
            return heldMention !== null && entityMentionKey(heldMention) === entityMentionKey(mention);
        });
    }, [findMentionWidgets]);
    const mentionEntity = useCallback((target) => {
        const mention = buildEntityMentionFields(target);
        if (mention === null)
            return false;
        if (isMentioned(target))
            return true;
        const mentionWidgets = findMentionWidgets();
        if (mentionWidgets.length >= MAX_ENTITY_MENTIONS_PER_MESSAGE) {
            replaceComposerWidgetFields(mentionWidgets[0].composerWidgetId, toEntityMentionWireFields(mention));
        }
        else {
            addComposerWidget(ENTITY_MENTION_WIDGET_NAME, toEntityMentionWireFields(mention));
        }
        return true;
    }, [isMentioned, findMentionWidgets, replaceComposerWidgetFields, addComposerWidget]);
    return { isMentioned, mentionEntity };
};
