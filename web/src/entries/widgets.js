// `@mgamadeus/ddd-agents/widgets` — the generic widgets the package registers itself, the canvas card and the modern
// look primitives an app's own widgets build on.
export { CanvasLauncherWidget } from "../widgets/CanvasLauncherWidget/CanvasLauncherWidget";
/** @deprecated Renamed to `CanvasLauncherWidget` (and `CanvasLauncherWidgetProps`, `CanvasLauncherWidgetCanvas`). */
export { CanvasLauncherWidget as CanvasWidgetCard, } from "../widgets/CanvasLauncherWidget/CanvasLauncherWidget";
export { CanvasWidgetSwitch } from "../widgets/CanvasLauncherWidget/CanvasWidgetSwitch";
export { canvasForMessageWidget } from "../widgets/CanvasLauncherWidget/canvas-for-message-widget";
export { ClockConversationWidget } from "../widgets/ClockConversationWidget/ClockConversationWidget";
export { ConceptExplainerConversationWidget } from "../widgets/ConceptExplainerConversationWidget/ConceptExplainerConversationWidget";
export { ConversationSearchResultsConversationWidget } from "../widgets/ConversationSearchResultsConversationWidget/ConversationSearchResultsConversationWidget";
export { CurrencyConverterConversationWidget } from "../widgets/CurrencyConverterConversationWidget/CurrencyConverterConversationWidget";
export { DriveFilesConversationWidget } from "../widgets/DriveFilesConversationWidget/DriveFilesConversationWidget";
// The DriveFiles card's seam: the cards a host's store reads (`registerDriveFileCardsSource` sits in the root entry).
export { DRIVE_FILE_CARDS_SOURCE_MISSING, readDriveFileCardsSource, unregisterDriveFileCardsSource, } from "../widgets/DriveFilesConversationWidget/shared/drive-file-cards-source";
export { driveFileCanvasTargetOf, readDriveFileCanvasOpener, unregisterDriveFileCanvasOpener, } from "../widgets/DriveFilesConversationWidget/shared/drive-file-canvas-openers";
export { DRIVE_FILES_MAX_FILES, DRIVE_FILES_WIDGET_TYPE, driveFileCardToOpen, driveFileFamilyOf, driveFileTypeLabelOf, formatDriveFileSize, isDriveFileCardExpired, nodeIdOfReference, parseDriveFilesPayload, safeDriveFileOpenLink, } from "../widgets/DriveFilesConversationWidget/shared/drive-files";
export { CustomVisualizationConversationWidget } from "../widgets/CustomVisualizationConversationWidget/CustomVisualizationConversationWidget";
// Where the custom visualization's sandbox frame loads from: a host sets `frameUrl` (e.g. to a host of another
// registrable domain, so the frame runs out of process) before the first widget renders.
export { CUSTOM_VISUALIZATION_SANDBOX_CONFIG, resolveCustomVisualizationFrameUrl, } from "../widgets/CustomVisualizationConversationWidget/shared/custom-visualization-sandbox-config";
export { EntityMentionConversationWidget } from "../widgets/EntityMentionConversationWidget/EntityMentionConversationWidget";
export { MultiChoiceConversationWidget } from "../widgets/MultiChoiceConversationWidget/MultiChoiceConversationWidget";
export { QuickRepliesConversationWidget } from "../widgets/QuickRepliesConversationWidget/QuickRepliesConversationWidget";
export { SuggestedActions } from "../widgets/SuggestedActions/SuggestedActions";
export { ToolTraceConversationWidget } from "../widgets/ToolTraceConversationWidget/ToolTraceConversationWidget";
export { WebSearchResultsConversationWidget } from "../widgets/WebSearchResultsConversationWidget/WebSearchResultsConversationWidget";
export { WritingDraftConversationWidget } from "../widgets/WritingDraftConversationWidget/WritingDraftConversationWidget";
export * from "../widgets/_shared/modern";
export { CANVAS_VIABLE_MIN_WIDTH, CanvasViabilityContext, ElementWidthCanvasViability, readCanvasViable, useCanvasViable, } from "../widgets/CanvasLauncherWidget/use-canvas-viable";
export { WidgetLabel } from "../widgets/_shared/WidgetLabel";
export { WidgetFooter } from "../widgets/_shared/WidgetFooter";
/** @deprecated Renamed to `WidgetFooter`. */
export { WidgetFooter as CardFooter } from "../widgets/_shared/WidgetFooter";
export { DonePill } from "../widgets/_shared/DonePill";
export { SkeletonCircle, SkeletonLine, SkeletonPill, SkeletonWidgetLabel } from "../widgets/_shared/skeleton-primitives";
export { pickAllowed, pickAllowedValue, pickReviewExternalId, pickSafeAlias, pickSafeAliases, pickText, } from "../widgets/_shared/widget-payload-validation";
export { createEntityUpdateChannel } from "../widgets/_shared/entity-update-channel";
export { useElementVisible } from "../widgets/_shared/use-element-visible";
export { normalizeSearchHitExcerpt } from "../widgets/ConversationSearchResultsConversationWidget/shared/search-hit-excerpt";
export { readConceptExplainerNumber, readConceptExplainerValue, } from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-widget-payload";
export { formatConceptExplainerNumber } from "../widgets/ConceptExplainerConversationWidget/shared/use-concept-explainer-language";
// The pictures of files by reference and the two widgets on them (`registerFilePreviewSource` sits in the root entry).
export { FILE_PREVIEW_SOURCE_MISSING, readFilePreviewSource, unregisterFilePreviewSource, useFilePictureUrl, } from "../widgets/_shared/file-preview-source";
export { ImageGalleryConversationWidget } from "../widgets/ImageGalleryConversationWidget/ImageGalleryConversationWidget";
export { IMAGE_GALLERY_MAX_FILES, IMAGE_GALLERY_WIDGET_TYPE, buildImageGalleryDeclineSentence, buildImageGalleryPickSentence, parseImageGalleryPayload, } from "../widgets/ImageGalleryConversationWidget/shared/image-gallery-payload";
export { AttachedFilesConversationWidget } from "../widgets/AttachedFilesConversationWidget/AttachedFilesConversationWidget";
export { AttachedFilesStrip } from "../widgets/AttachedFilesConversationWidget/AttachedFilesStrip";
export { ATTACHED_FILES_MAX, ATTACHED_FILES_WIDGET_TYPE, buildAttachedFilesComposerWidget, readAttachedFiles, } from "../widgets/AttachedFilesConversationWidget/attached-files";
