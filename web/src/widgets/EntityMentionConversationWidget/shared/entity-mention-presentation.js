import { textKey } from "../../../provider/text-key";
/** The component-library glyph of an entity type — what the card's icon tile shows; a type not listed here gets `details`. */
const ENTITY_TYPE_ICONS = {
    competitor: "business",
    review: "star",
    keyword: "search",
    location: "location",
    business: "home",
    post: "post",
    page: "browser",
};
/** The name line for an entity without a label — one static translation key per type (no concatenated noun). */
const ENTITY_TYPE_FALLBACK_LABELS = {
    competitor: textKey("This competitor"),
    review: textKey("This review"),
    keyword: textKey("This keyword"),
    location: textKey("This location"),
    business: textKey("This business"),
    post: textKey("This post"),
    page: textKey("This page"),
};
export const FALLBACK_ENTITY_LABEL = textKey("About this entry");
export const entityTypeIcon = (entityType) => ENTITY_TYPE_ICONS[entityType] ?? "details";
export const entityTypeFallbackLabel = (entityType) => ENTITY_TYPE_FALLBACK_LABELS[entityType] ?? FALLBACK_ENTITY_LABEL;
