const SENTENCE_PATTERN = /[^.!?…]+[.!?…]*\s*/g;
/** Letters and digits only, lower case: the browser's transcript and the saved sentence differ in spacing and marks. */
const normalizeForMatching = (text) => text.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, "");
/**
 * What was said in this call that the chat does not show yet: the call's lines with every sentence the backend has
 * already saved as a message taken out, so only the unsaved rest streams at the chat's end and gives way to the real
 * message once it arrives. Per speaker, the saved texts are read as one stream in order, and each sentence of the
 * speaker's lines is looked for from where the last found one ended: the backend may cut sentences elsewhere, and a
 * sentence said twice is matched twice. Lines with nothing unsaved left are dropped.
 */
export const buildUnsavedAgentVoiceCallLines = (lines, savedMessages) => {
    const savedStreamBySpeaker = { agent: "", owner: "" };
    savedMessages.forEach((savedMessage) => {
        savedStreamBySpeaker[savedMessage.speaker] += normalizeForMatching(savedMessage.text);
    });
    const matchedUntilBySpeaker = { agent: 0, owner: 0 };
    return lines.flatMap((line) => {
        const sentences = line.text.match(SENTENCE_PATTERN) ?? [];
        const unsavedSentences = sentences.filter((sentence) => {
            const normalizedSentence = normalizeForMatching(sentence);
            if (normalizedSentence === "")
                return false;
            const foundAt = savedStreamBySpeaker[line.speaker].indexOf(normalizedSentence, matchedUntilBySpeaker[line.speaker]);
            if (foundAt === -1)
                return true;
            matchedUntilBySpeaker[line.speaker] = foundAt + normalizedSentence.length;
            return false;
        });
        const unsavedText = unsavedSentences.join("").trim();
        return unsavedText === "" ? [] : [{ ...line, text: unsavedText }];
    });
};
