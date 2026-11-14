import type React from "react";
import { createContext, useContext } from "react";

/**
 * The bodies of the `entityDetail` canvas, one per entity type: every `*.entity-detail-body.tsx` below
 * `widgets/` that exports `entityDetailBody` registers itself by folder glob — an entity type is added with
 * the backend `<Type>EntityReferenceResolver` and this one file, and edits no list. A second body for one entity type
 * is a build error (thrown at module load).
 */

export interface EntityDetailBodyProps {
  entityType: string;
  /** The id as the backend resolved it (a row id or an external id). */
  entityId: string;
  /** The key of the agent space the chat is scoped to, for a body whose read endpoint is agent-space-scoped. */
  agentSpaceKey?: number;
}

export interface EntityDetailBodyDefinition {
  /** The backend resolver's `ENTITY_TYPE`. */
  entityType: string;
  /** Self-hydrates the entity by id through its own read endpoint (the endpoint's entity rights apply). */
  component: React.ComponentType<EntityDetailBodyProps>;
}

const bodyModules = import.meta.glob<{ entityDetailBody?: EntityDetailBodyDefinition }>(
  ["../../widgets/**/*.entity-detail-body.tsx", "../**/*.entity-detail-body.tsx"],
  { eager: true },
);

export const buildEntityDetailBodyRegistry = (
  definitions: EntityDetailBodyDefinition[],
): Readonly<Record<string, EntityDetailBodyDefinition>> => {
  const registry: Record<string, EntityDetailBodyDefinition> = {};
  for (const definition of definitions) {
    if (Object.prototype.hasOwnProperty.call(registry, definition.entityType)) {
      throw new Error(`Two entity detail bodies register the entity type "${definition.entityType}".`);
    }
    registry[definition.entityType] = definition;
  }
  return registry;
};

/** The body definitions a set of `*.entity-detail-body.tsx` modules export (the package's or an app's glob). */
export const readEntityDetailBodyDefinitions = (
  modules: Record<string, { entityDetailBody?: EntityDetailBodyDefinition }>,
): EntityDetailBodyDefinition[] =>
  Object.values(modules)
    .map((bodyModule) => bodyModule.entityDetailBody)
    .filter((definition): definition is EntityDetailBodyDefinition => definition !== undefined);

/** Entity type → body: the package's folder, then what an app registers (`registerEntityDetailBodies`). */
export const entityDetailBodies: Record<string, EntityDetailBodyDefinition> = {
  ...buildEntityDetailBodyRegistry(readEntityDetailBodyDefinitions(bodyModules)),
};

/** Adds an app's entity detail bodies. Runs outside React, before the first render; an entity type registers once. */
export const registerEntityDetailBodies = (definitions: readonly EntityDetailBodyDefinition[]): void => {
  Object.assign(
    entityDetailBodies,
    buildEntityDetailBodyRegistry([...Object.values(entityDetailBodies), ...definitions]),
  );
};

/** The registry the canvas body uses — the folder's by default; a spec or a story may provide stubs. */
export const EntityDetailBodiesContext =
  createContext<Readonly<Record<string, EntityDetailBodyDefinition>>>(entityDetailBodies);

export const useEntityDetailBodies = () => useContext(EntityDetailBodiesContext);
