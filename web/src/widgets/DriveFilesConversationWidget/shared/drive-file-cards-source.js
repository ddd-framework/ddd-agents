import { registerWidgetHydrator } from "../../../renderer/widget-hydration/widget-hydration.registry";
import { buildDriveFilesHydrationKey, DRIVE_FILES_WIDGET_TYPE, } from "./drive-files";
/** The hydration error of a card read while no source is registered: the widget shows "not connected". */
export const DRIVE_FILE_CARDS_SOURCE_MISSING = "DRIVE_FILE_CARDS_SOURCE_MISSING";
let registeredSource = null;
/**
 * Registers the store behind the `DriveFiles` cards. Call it once, outside React, before the first chat; a second call
 * replaces the first.
 */
export const registerDriveFileCardsSource = (source) => {
    registeredSource = source;
};
/** The registered source, or `null` while no store is connected to this frontend. */
export const readDriveFileCardsSource = () => registeredSource;
/** Takes the source off again (tests, a host that disconnects its store). */
export const unregisterDriveFileCardsSource = () => {
    registeredSource = null;
};
/**
 * The package hydrator of the widget: reads through the registered source, fails with
 * {@link DRIVE_FILE_CARDS_SOURCE_MISSING} without one. Registered when the widget registers, so a preview's fixture
 * hydration answers it like any other hydrator.
 */
export const registerDriveFilesHydrator = () => {
    registerWidgetHydrator(DRIVE_FILES_WIDGET_TYPE, {
        hydrator: (request, { signal }) => {
            const source = readDriveFileCardsSource();
            if (source === null)
                return Promise.reject(new Error(DRIVE_FILE_CARDS_SOURCE_MISSING));
            return source.readFileCards(request, signal);
        },
        deriveKey: buildDriveFilesHydrationKey,
        isPayloadEqual: (previous, next) => previous !== undefined &&
            buildDriveFilesHydrationKey(DRIVE_FILES_WIDGET_TYPE, previous) ===
                buildDriveFilesHydrationKey(DRIVE_FILES_WIDGET_TYPE, next),
    });
};
