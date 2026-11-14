import { createContext, useContext } from "react";
import type { ComposedViewComponentDefinition } from "./composed-view-component-types";

/**
 * The kit's component registry: every `components/**\/*.composed.tsx` that exports
 * `composedViewComponent` registers itself by folder glob — a component is added with one new file and edits no list.
 * A second component for one node type is a build error (thrown at module load, caught by the spec).
 */
const componentModules = import.meta.glob<{ composedViewComponent?: ComposedViewComponentDefinition }>(
  "./components/**/*.composed.tsx",
  { eager: true },
);

export const buildComposedViewComponentRegistry = (
  definitions: ComposedViewComponentDefinition[],
): Readonly<Record<string, ComposedViewComponentDefinition>> => {
  const registry: Record<string, ComposedViewComponentDefinition> = {};
  for (const definition of definitions) {
    if (Object.prototype.hasOwnProperty.call(registry, definition.nodeType)) {
      throw new Error(`Two ComposedView components register the node type "${definition.nodeType}".`);
    }
    registry[definition.nodeType] = definition;
  }
  return registry;
};

/** Node type → component, from the folder. */
export const composedViewComponents = buildComposedViewComponentRegistry(
  Object.values(componentModules)
    .map((componentModule) => componentModule.composedViewComponent)
    .filter((definition): definition is ComposedViewComponentDefinition => definition !== undefined),
);

/** The registry the renderer uses — the folder's by default; a story or a spec may provide its own stub components. */
export const ComposedViewComponentsContext =
  createContext<Readonly<Record<string, ComposedViewComponentDefinition>>>(composedViewComponents);

export const useComposedViewComponents = () => useContext(ComposedViewComponentsContext);
