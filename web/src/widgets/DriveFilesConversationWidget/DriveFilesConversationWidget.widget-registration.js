import { DriveFilesConversationWidget } from "./DriveFilesConversationWidget";
import { registerDriveFilesHydrator } from "./shared/drive-file-cards-source";
// The hydrator reads through the host's `DriveFileCardsSource` (none registered: the card says the drive is not
// connected). Registered with the widget, so a preview's fixture hydration answers it like any other hydrator.
registerDriveFilesHydrator();
/**
 * `DriveFiles`: the file cards of a drive store the agent names with `showDriveFiles`. Ungated: the cards are read
 * under the viewer's rights, a file the viewer may not read is left out by the store.
 */
export const conversationWidgetRegistration = {
    name: "DriveFiles",
    component: DriveFilesConversationWidget,
};
