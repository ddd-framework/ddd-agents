export const CUSTOM_VISUALIZATION_LIBRARIES = [
    {
        name: "d3",
        version: "7.6.1",
        globalName: "d3",
        fileName: "d3-7.6.1.min.js.txt",
        sha256: "b384cf0baeec03aa9e1443fd670cd41c9ec984def9a7b4dbfd7ad2f197d5b573",
        loadSource: () => import("../sandbox-frame/libraries/d3-7.6.1.min.js.txt?raw").then((module) => module.default),
    },
];
export const CUSTOM_VISUALIZATION_LIBRARY_NAMES = CUSTOM_VISUALIZATION_LIBRARIES.map((library) => library.name);
export const findCustomVisualizationLibrary = (libraryName) => CUSTOM_VISUALIZATION_LIBRARIES.find((library) => library.name === libraryName);
/** The sources of the named libraries, in the given order; every name must be on the allow-list. */
export const loadCustomVisualizationLibrarySources = (libraryNames) => Promise.all(libraryNames.map((libraryName) => {
    const library = findCustomVisualizationLibrary(libraryName);
    return library
        ? library.loadSource()
        : Promise.reject(new Error(`The library "${libraryName}" is not on the allow-list.`));
}));
