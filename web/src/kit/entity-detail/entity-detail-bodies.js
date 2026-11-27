import { createContext, useContext } from "react";
const bodyModules = import.meta.glob(["../../widgets/**/*.entity-detail-body.tsx", "../**/*.entity-detail-body.tsx"], { eager: true });
export const buildEntityDetailBodyRegistry = (definitions) => {
    const registry = {};
    for (const definition of definitions) {
        if (Object.prototype.hasOwnProperty.call(registry, definition.entityType)) {
            throw new Error(`Two entity detail bodies register the entity type "${definition.entityType}".`);
        }
        registry[definition.entityType] = definition;
    }
    return registry;
};
/** The body definitions a set of `*.entity-detail-body.tsx` modules export (the package's or an app's glob). */
export const readEntityDetailBodyDefinitions = (modules) => Object.values(modules)
    .map((bodyModule) => bodyModule.entityDetailBody)
    .filter((definition) => definition !== undefined);
/** Entity type → body: the package's folder, then what an app registers (`registerEntityDetailBodies`). */
export const entityDetailBodies = {
    ...buildEntityDetailBodyRegistry(readEntityDetailBodyDefinitions(bodyModules)),
};
/** Adds an app's entity detail bodies. Runs outside React, before the first render; an entity type registers once. */
export const registerEntityDetailBodies = (definitions) => {
    Object.assign(entityDetailBodies, buildEntityDetailBodyRegistry([...Object.values(entityDetailBodies), ...definitions]));
};
/** The body registered for one entity type (the package's or an app's), or undefined when none is. */
export const getEntityDetailBody = (entityType) => Object.prototype.hasOwnProperty.call(entityDetailBodies, entityType) ? entityDetailBodies[entityType] : undefined;
/** The registry the canvas body uses — the folder's by default; a spec or a story may provide stubs. */
export const EntityDetailBodiesContext = createContext(entityDetailBodies);
export const useEntityDetailBodies = () => useContext(EntityDetailBodiesContext);
