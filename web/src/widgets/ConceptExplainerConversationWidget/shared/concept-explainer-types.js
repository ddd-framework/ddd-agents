/**
 * The ConceptExplainer type registry: every package `widgets/**\/*.concept-explainer.tsx` that
 * exports `conceptExplainerType` registers itself by folder glob — a domain adds a type with one new file and edits no
 * list. An app registers its own types with `registerConceptExplainerTypes` (its glob stays in its code). Two files
 * for one type id are an error.
 */
const conceptExplainerTypeModules = import.meta.glob(["../../**/*.concept-explainer.tsx"], { eager: true });
export const buildConceptExplainerTypeRegistry = (definitions) => {
    const registry = {};
    for (const definition of definitions) {
        if (Object.prototype.hasOwnProperty.call(registry, definition.typeId)) {
            throw new Error(`Two ConceptExplainer types register the type id "${definition.typeId}".`);
        }
        registry[definition.typeId] = definition;
    }
    return registry;
};
/** The type definitions a set of `*.concept-explainer.tsx` modules export (the package's or an app's glob). */
export const readConceptExplainerTypeDefinitions = (modules) => Object.values(modules)
    .map((typeModule) => typeModule.conceptExplainerType)
    .filter((definition) => definition !== undefined);
/** Type id → type: the package's folders, then what an app registers (`registerConceptExplainerTypes`). */
export const conceptExplainerTypes = {
    ...buildConceptExplainerTypeRegistry(readConceptExplainerTypeDefinitions(conceptExplainerTypeModules)),
};
/** Adds an app's ConceptExplainer types. Runs outside React, before the first render; a type id registers once. */
export const registerConceptExplainerTypes = (definitions) => {
    Object.assign(conceptExplainerTypes, buildConceptExplainerTypeRegistry([...Object.values(conceptExplainerTypes), ...definitions]));
};
/** `rankingFactors`, `RankingFactors`, `ranking_factors` and `ranking-factors` all read as `rankingfactors`. */
export const normalizeConceptExplainerTypeId = (typeId) => typeId.toLowerCase().replace(/[^a-z0-9]/g, "");
/**
 * The registered type of an id — exact first, then ignoring case, `_` and `-` (a model writes `RankingFactors` or
 * `ranking_factors`; the backend's `ConceptExplainerTypes::findTypeClass()` applies the same rule); else undefined.
 */
export const findConceptExplainerType = (typeId, registry = conceptExplainerTypes) => {
    if (Object.prototype.hasOwnProperty.call(registry, typeId))
        return registry[typeId];
    const normalizedTypeId = normalizeConceptExplainerTypeId(typeId);
    if (normalizedTypeId === "")
        return undefined;
    return Object.values(registry).find((definition) => normalizeConceptExplainerTypeId(definition.typeId) === normalizedTypeId);
};
