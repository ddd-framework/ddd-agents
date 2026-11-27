import { DEFAULT_AGENTS_TEXTS } from "../../provider/environment";
import { textKey } from "../../provider/text-key";
/**
 * The caller line of the incoming call. The ring carries a label KEY (`callerLabelKey`), never the text: the client
 * owns the text. Every key this bundle knows maps to a text key it renders through the text function;
 * an unknown key (a backend newer than this bundle) is shown as it came, its placeholders filled but untranslated:
 * never a raw string sent to the translator, which could never be registered as a key.
 */
const DEFAULT_CALLER_LABEL_TEXT_KEY = textKey("%agentName% wants to talk");
// In the browser window it is a voice chat with the agent, never a call: a key worded as a call reads as a voice chat.
const CALLER_LABEL_TEXT_KEYS_BY_KEY = {
    "%agentName% is calling you": DEFAULT_CALLER_LABEL_TEXT_KEY,
    "%agentName% wants to talk": DEFAULT_CALLER_LABEL_TEXT_KEY,
};
/** The text key of a ring's caller line; a ring without a label key gets the bundle's own line. */
export const pickAgentVoiceCallerLabelTextKey = (callerLabelKey) => {
    if (callerLabelKey === "")
        return { labelTextKey: DEFAULT_CALLER_LABEL_TEXT_KEY, isTranslatable: true };
    return Object.prototype.hasOwnProperty.call(CALLER_LABEL_TEXT_KEYS_BY_KEY, callerLabelKey)
        ? { labelTextKey: CALLER_LABEL_TEXT_KEYS_BY_KEY[callerLabelKey], isTranslatable: true }
        : { labelTextKey: callerLabelKey, isTranslatable: false };
};
/**
 * The caller line of a ring where the application resolves none: the ring's label key through `translate` (the voice
 * components' text function, which fills `%agentName%`), an unknown key with `agentName` filled in.
 */
export const buildAgentVoiceCallerLabel = (ring, translate, agentName) => {
    const { labelTextKey, isTranslatable } = pickAgentVoiceCallerLabelTextKey(ring.callerLabelKey);
    // The package's untranslated default fills the placeholders of a key no translator knows.
    return { text: isTranslatable ? translate(labelTextKey) : DEFAULT_AGENTS_TEXTS.translate(labelTextKey, { agentName }) };
};
