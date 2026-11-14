import { type AgentsIconName } from "../../../provider/components";

/** The component-library glyph of an entity type — what the card's icon tile shows; a type not listed here gets `details`. */
const ENTITY_TYPE_ICONS: Record<string, AgentsIconName> = {
  competitor: "business",
  review: "star",
  keyword: "search",
  location: "location",
  business: "home",
  post: "post",
  page: "browser",
};

/** The name line for an entity without a label — one static translation key per type (no concatenated noun). */
const ENTITY_TYPE_FALLBACK_LABELS: Record<string, string> = {
  competitor: "This competitor",
  review: "This review",
  keyword: "This keyword",
  location: "This location",
  business: "This business",
  post: "This post",
  page: "This page",
};

export const FALLBACK_ENTITY_LABEL = "About this entry";

export const entityTypeIcon = (entityType: string): AgentsIconName => ENTITY_TYPE_ICONS[entityType] ?? "details";

export const entityTypeFallbackLabel = (entityType: string): string =>
  ENTITY_TYPE_FALLBACK_LABELS[entityType] ?? FALLBACK_ENTITY_LABEL;
