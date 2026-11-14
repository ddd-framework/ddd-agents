/**
 * The fixed allow-list of libraries a CustomVisualization may ask for (libraries from a fixed allow-list
 * served by us). Each bundle is a pinned copy in `sandbox-frame/libraries/` (stored as `.txt`, so linters and type
 * checks skip the minified code), loaded lazily into its own chunk of our build and handed to the sandbox frame inside
 * the render message — the frame loads no script from any URL. The SHA-256 of each file is pinned here and checked by
 * `sandbox-frame/_custom-visualization-frame.spec.ts`, so a changed bundle is a deliberate re-pin. A block that names a
 * library outside this list is not run (it reports `failed`).
 */
export interface CustomVisualizationLibrary {
  /** The name the block writes in `libraries`. */
  name: string;
  version: string;
  /** The global the bundle defines in the frame (`window.d3`). */
  globalName: string;
  /** The bundle file in `sandbox-frame/libraries/`. */
  fileName: string;
  /** SHA-256 of the file, hex. */
  sha256: string;
  loadSource: () => Promise<string>;
}

export const CUSTOM_VISUALIZATION_LIBRARIES: readonly CustomVisualizationLibrary[] = [
  {
    name: "d3",
    version: "7.6.1",
    globalName: "d3",
    fileName: "d3-7.6.1.min.js.txt",
    sha256: "b384cf0baeec03aa9e1443fd670cd41c9ec984def9a7b4dbfd7ad2f197d5b573",
    loadSource: () => import("../sandbox-frame/libraries/d3-7.6.1.min.js.txt?raw").then((module) => module.default),
  },
];

export const CUSTOM_VISUALIZATION_LIBRARY_NAMES: readonly string[] = CUSTOM_VISUALIZATION_LIBRARIES.map(
  (library) => library.name,
);

export const findCustomVisualizationLibrary = (libraryName: string): CustomVisualizationLibrary | undefined =>
  CUSTOM_VISUALIZATION_LIBRARIES.find((library) => library.name === libraryName);

/** The sources of the named libraries, in the given order; every name must be on the allow-list. */
export const loadCustomVisualizationLibrarySources = (libraryNames: readonly string[]): Promise<string[]> =>
  Promise.all(
    libraryNames.map((libraryName) => {
      const library = findCustomVisualizationLibrary(libraryName);
      return library
        ? library.loadSource()
        : Promise.reject(new Error(`The library "${libraryName}" is not on the allow-list.`));
    }),
  );
