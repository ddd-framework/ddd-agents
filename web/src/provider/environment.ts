import type { ComponentType, ErrorInfo, ReactNode } from "react";
import type { AgentsComponents } from "./components/types";
import type { ConversationWidgetStatePartition } from "../renderer/conversation-widget-state-partition";
import type { ChatAssistantCategory } from "../chat/chat-assistant-identity";
import type { ComposedNodeSchemaApplicationPart } from "../renderer/widget-tree/composed-node-schema";
import type { CanvasBehaviour } from "../renderer/canvas/canvas-behaviours";

/** Translates interface copy. The key is the English source text. */
export interface AgentsTexts {
  translate: (key: string, replacements?: Record<string, string | number>, context?: string) => string;
}

/** One image of a gallery the app opens over the page. */
export interface AgentsGalleryImage {
  /** Identifies the image within the gallery. */
  id: string;
  url: string;
  name?: string;
}

/** An action the package dispatches into the state store. */
export interface AgentsStateAction {
  type: string;
  payload?: unknown;
}

/**
 * A state store as React hooks: the package keeps its widget hydration state in it, and an app's older chat state
 * partitions are read through it (`defaultChatStatePartition`). Without an app store the package uses its own.
 */
export interface AgentsStateStore {
  useSelector: <Selected>(selector: (state: any) => Selected) => Selected;
  useDispatch: () => (action: AgentsStateAction) => unknown;
}

/** The owner's language and the active agent space's date and time settings. Every field may be missing. */
export interface AgentsOwnerSettings {
  /** The owner's language (`de`). */
  languageCode?: string | null;
  /** The owner's locale (`de-DE`). */
  locale?: string | null;
  /** The date locale of the active agent space (`de-DE`). */
  dateLocale?: string | null;
  /** The time zone of the active agent space. */
  timeZone?: string | null;
  /** The active agent space writes times with AM/PM. */
  usesAmPm?: boolean | null;
}

/** What the error card of a reached budget offers (the error card's upgrade data). */
export interface AgentsBudgetLimit {
  /** An upgrade lifts the limit: the card offers it. */
  canUpgrade: boolean;
  /** Opens the app's upgrade flow. */
  openUpgrade: () => void;
  /** The app presents the budget as a fair-use allowance (the card uses that wording). */
  showsFairUseWording: boolean;
}

/** The badge of a named assistant's icon (its intro card, its openings). */
export interface AgentsAssistantIconBadgeProps {
  /** The icon's name in the app's icon set (`ChatAssistantIdentity.iconClass`). */
  iconName: string;
  color: string;
  backgroundColor: string;
  /** Larger on the intro card than on an opening. */
  isLarge?: boolean;
}

/** The background of a chat with a named assistant's category (or with none, the overview of every category). */
export interface AgentsAssistantBackgroundProps {
  category?: ChatAssistantCategory;
  /** The category's alias; undefined for a chat without a category. */
  categoryAlias?: string;
}

/** The state of the app's map script loader. */
export interface AgentsMapsLoaderState {
  isLoaded: boolean;
  loadError: Error | undefined;
  languageCode: string | undefined;
}

/**
 * Further names of the sandbox and readiness signals that an app declares beside the package's own (`AGENTS_VISUALIZATION`,
 * `--agents-*`, `__AGENTS_WIDGET_IMAGE_V1__`, `__AGENTS_WIDGET_PREVIEW_V1__`): the names its stored visualization scripts,
 * its skill texts or its headless capture already use. Every list is optional; the package's names always exist.
 */
export type AgentsSandboxLegacyAliases = {
  /** Further names of the sandbox global, e.g. the name stored scripts of the app read. */
  visualizationGlobalNames?: string[];
  /** Further prefixes of the frame's theme variables, e.g. `--app-` (each variable is set under every prefix). */
  themeVariablePrefixes?: string[];
  /** Further names of the image-capture readiness signal on `window`. */
  imageReadinessGlobalNames?: string[];
  /** Further names of the preview readiness signal on `window`. */
  previewReadinessGlobalNames?: string[];
};

/**
 * Everything an app supplies to the package. Every member is optional: the package has a default for each.
 * An app sets it once at start (`setAgentsEnvironment`) or per subtree (`AgentsProvider`); the functions are read
 * from the app-wide environment only.
 */
export interface AgentsEnvironment {
  /**
   * The state store hooks (see `AgentsStateStore`). Default: the package's own widget hydration store (one per page,
   * `createAgentsWidgetHydrationStateStore`). An app supplies its own to keep the hydration state in its store
   * (host application: its Redux store, which mounts `WidgetHydrationReducer` under `AIExtensionsWidgetHydration`).
   */
  stateStore?: AgentsStateStore;
  /**
   * The partition of the app's own chat state a widget provider reads when its host passes neither a chat store nor a
   * partition (host application: the AI-Extensions chat's slice). Default: none — such a provider keeps a store of its own.
   */
  defaultChatStatePartition?: ConversationWidgetStatePartition;
  /** A React hook returning the owner's settings. Default: none known (the browser's language stands in). */
  useOwnerSettings?: () => AgentsOwnerSettings;
  /**
   * A React hook returning the key of the agent space the owner works in. The key of an agent space is what the
   * client knows it by: the id of the space, unless the app's backend hands out another key
   * (`AgentSpace::getWidgetSelfHydrationKey()`). Default: no agent space.
   */
  useActiveAgentSpaceKey?: () => number | undefined;
  /** Reads the display name of an agent space (the header of a widget of another agent space). Default: no name. */
  readAgentSpaceName?: (agentSpaceKey: number, signal: AbortSignal) => Promise<string | undefined>;
  /**
   * The field of a widget block that carries the key of the block's agent space, as the app's backend writes it
   * (`WidgetBlockFieldNames::WIDGET_SELF_HYDRATION_KEY`). An app whose stored conversations use another field name
   * declares it here and in its backend class. Default: `agentSpaceId`.
   */
  widgetSelfHydrationKeyFieldName?: string;
  /**
   * The owner's text for a click on a widget of another agent space than the active one: returns the text with the
   * suffix the app's backend reads that agent space from
   * (`WidgetBlockFieldNames::OWNER_TEXT_SUFFIX_PATTERN_OF_ANOTHER_SPACE`). Default: the text unchanged.
   */
  appendAnotherAgentSpaceToOwnerText?: (ownerText: string, anotherAgentSpaceKey: number) => string;
  /** The label of an agent space whose name is not known. Default: `#<key>`. */
  buildUnnamedAgentSpaceLabel?: (agentSpaceKey: number) => string;
  /**
   * The sentence that tells the owner that a widget shows another agent space than the active one; `agentSpaceLabel`
   * is its name or the label above. Default: a sentence that names no kind of agent space.
   */
  buildWidgetOfAnotherAgentSpaceExplanation?: (agentSpaceLabel: string) => string;
  /** The name the owner knows the agent by, for the interface texts that name it. Default: `the assistant`. */
  agentDisplayName?: string;
  /** A React hook returning what the error card of a reached budget offers. Default: no upgrade, the plain wording. */
  useBudgetLimit?: () => AgentsBudgetLimit;
  /** A React hook returning the map script loader's state. Default: no maps. */
  useMapsLoader?: () => AgentsMapsLoaderState;
  /**
   * The action that tells the app whether an agent chat surface is open (dispatched into the state store when the
   * first surface mounts and the last unmounts). Default: nothing is dispatched.
   */
  buildChatSurfaceOpenChangedAction?: (isOpen: boolean) => AgentsStateAction;
  components?: Partial<AgentsComponents>;
  texts?: AgentsTexts;
  /**
   * Prepares a plain text (a model's words, a business name) for the app's `Text`, `Button` and `StatusBadge`
   * replacements, which may read a string as markup. Default: the text unchanged.
   */
  escapePlainTextForText?: (text: string) => string;
  /** Opens the images in the app's lightbox, starting at `startIndex`. Default: the start image in a new tab. */
  openImageGallery?: (images: AgentsGalleryImage[], startIndex: number) => void;
  /** Reports a widget that crashed while rendering. Default: nothing (the crash is logged to the console). */
  reportWidgetCrash?: (widgetName: string, error: Error, errorInfo: ErrorInfo) => void;
  /**
   * The footer under an assistant message (host application: the admin's forensics panel, which gates itself). Receives the
   * message as the message view got it. Default: none.
   */
  MessageFooter?: ComponentType<{ message?: unknown }>;
  /**
   * The colour theme class of a named assistant's category (the assistant identity and theme). Default:
   * none — the generic chat colours.
   */
  readAssistantThemeClassName?: (categoryAlias: string) => string;
  /** The badge of a named assistant's icon. Default: none. */
  AssistantIconBadge?: ComponentType<AgentsAssistantIconBadgeProps>;
  /** The chat's own background when the host paints none (host application: the category illustrations). Default: none. */
  AssistantBackground?: ComponentType<AgentsAssistantBackgroundProps>;
  /**
   * Wraps the body of an open canvas (host application: page components inside a canvas must not mirror their filters into
   * the page URL). Default: no wrapper.
   */
  CanvasBodyContainer?: ComponentType<{ children: ReactNode }>;
  /**
   * Tells the app that a turn of the agent runs in an open chat (the host application keeps the tab "ambient active" then);
   * returns the release the chat calls when the turn ends. Default: nothing.
   */
  reportRunningTurn?: () => () => void;
  /**
   * Further names of the CustomVisualization sandbox (its global, its theme variable prefixes) and of the image and
   * preview readiness signals, beside the package's own names (see `AgentsSandboxLegacyAliases`). An app whose stored
   * scripts or capture read older names declares them here. Default: none — only the package's names exist.
   */
  sandboxLegacyAliases?: AgentsSandboxLegacyAliases;
  /**
   * The app's part of the ComposedView node schema: the node types, widgets (which embed, at which size), data sources
   * and entity types its own classes declare, as its backend generates them beside the package's
   * `composed-node-schema.json`. The tree readers and the kit read the package's schema with this part added
   * (`getComposedNodeSchema`). Default: none — only the package's own types exist.
   */
  composedNodeSchemaApplicationPart?: ComposedNodeSchemaApplicationPart;
  /**
   * The path prefix of the app's API reads a live widget image capture lets through (same origin, GET only, sent with
   * the capture's bearer; every other request of the capture fails it). Default: `/api/`.
   */
  widgetImageCaptureReadPathPrefix?: string;
  /**
   * The app's widgets that draw a Google map: a widget image capture of one of them, or of a `ComposedView` that embeds
   * one, may load the Maps SDK (the narrow transport exception of the capture; the twin of the backend's list that opens
   * the capture page's CSP). A `ComposedView` holding a `Map` node always may. Default: none.
   */
  googleMapWidgetNames?: string[];
  /**
   * Further `signalSource`s a widget click of the app posts (`injectSignal`), beside the package's own
   * (`FRONTEND_WIDGET_CLICK`, `OWNER_ACTION_CONFIRM`): the message list reads every one of them as the owner's answer to
   * a widget, so an answered widget never comes back interactive after a reload. Default: none.
   */
  furtherWidgetClickSignalSources?: string[];
  /**
   * The canvases of the app's widgets (canvas id → behaviour), beside the package's own (`packageCanvasBehaviours`):
   * every canvas an app widget lists in `opensCanvases`. The app registers each one's body with `registerCanvasBodies`
   * and, when gated, its requirement with `registerCanvasFeatureFlags`. A package canvas id here is ignored. Default:
   * none.
   */
  furtherCanvasBehaviours?: Readonly<Record<string, CanvasBehaviour>>;
}

let appEnvironment: AgentsEnvironment = {};
const environmentListeners = new Set<() => void>();

/** Sets the app-wide environment. Call it once before the first render; a later call re-renders the readers. */
export const setAgentsEnvironment = (environment: AgentsEnvironment): void => {
  appEnvironment = environment;
  for (const notifyListener of environmentListeners) notifyListener();
};

export const getAgentsEnvironment = (): AgentsEnvironment => appEnvironment;

export const subscribeToAgentsEnvironment = (listener: () => void): (() => void) => {
  environmentListeners.add(listener);
  return () => {
    environmentListeners.delete(listener);
  };
};

const substitutePlaceholders = (key: string, replacements?: Record<string, string | number>): string =>
  replacements
    ? key.replace(/%([A-Za-z0-9_]+)%/g, (placeholder, name: string) =>
        name in replacements ? String(replacements[name]) : placeholder,
      )
    : key;

/** The package default: the English key with its placeholders substituted. */
export const DEFAULT_AGENTS_TEXTS: AgentsTexts = { translate: substitutePlaceholders };

/** Translates an interface text through the app-wide environment, for a string a component hands on (a label). */
export const translateText = (key: string, replacements?: Record<string, string | number>, context?: string): string =>
  (appEnvironment.texts ?? DEFAULT_AGENTS_TEXTS).translate(key, replacements, context);

/** A plain text as the app's text components take it (`AgentsEnvironment.escapePlainTextForText`). */
export const escapePlainTextForText = (text: string): string =>
  appEnvironment.escapePlainTextForText ? appEnvironment.escapePlainTextForText(text) : text;

/** Opens an image gallery over the page (`AgentsEnvironment.openImageGallery`). */
export const openImageGallery = (images: AgentsGalleryImage[], startIndex: number): void => {
  if (appEnvironment.openImageGallery) {
    appEnvironment.openImageGallery(images, startIndex);
    return;
  }
  const startImage = images[startIndex];
  if (startImage) window.open(startImage.url, "_blank", "noopener,noreferrer");
};

/** The block field of the package's backend default (`WidgetBlockFieldNames::WIDGET_SELF_HYDRATION_KEY`). */
export const DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME = "agentSpaceId";

/** The field of a widget block that carries the key of its agent space (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`). */
export const getWidgetSelfHydrationKeyFieldName = (): string =>
  appEnvironment.widgetSelfHydrationKeyFieldName ?? DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME;

/** The owner's text for a click on a widget of another agent space (`AgentsEnvironment.appendAnotherAgentSpaceToOwnerText`). */
export const appendAnotherAgentSpaceToOwnerText = (ownerText: string, anotherAgentSpaceKey: number): string =>
  appEnvironment.appendAnotherAgentSpaceToOwnerText
    ? appEnvironment.appendAnotherAgentSpaceToOwnerText(ownerText, anotherAgentSpaceKey)
    : ownerText;

/** The label of an agent space without a known name (`AgentsEnvironment.buildUnnamedAgentSpaceLabel`). */
export const buildUnnamedAgentSpaceLabel = (agentSpaceKey: number): string =>
  appEnvironment.buildUnnamedAgentSpaceLabel ? appEnvironment.buildUnnamedAgentSpaceLabel(agentSpaceKey) : `#${agentSpaceKey}`;

/** The sentence under a widget of another agent space (`AgentsEnvironment.buildWidgetOfAnotherAgentSpaceExplanation`). */
export const buildWidgetOfAnotherAgentSpaceExplanation = (agentSpaceLabel: string): string =>
  appEnvironment.buildWidgetOfAnotherAgentSpaceExplanation
    ? appEnvironment.buildWidgetOfAnotherAgentSpaceExplanation(agentSpaceLabel)
    : translateText("This widget shows data for %label%, not for what you currently have selected.", {
        label: agentSpaceLabel,
      });

/** The further sandbox and readiness names the app declares (`AgentsEnvironment.sandboxLegacyAliases`). */
export const getSandboxLegacyAliases = (): AgentsSandboxLegacyAliases => appEnvironment.sandboxLegacyAliases ?? {};

/** The package default of `AgentsEnvironment.widgetImageCaptureReadPathPrefix`. */
export const DEFAULT_WIDGET_IMAGE_CAPTURE_READ_PATH_PREFIX = "/api/";

/** The path prefix of the API reads a live widget image capture lets through (`AgentsEnvironment.widgetImageCaptureReadPathPrefix`). */
export const getWidgetImageCaptureReadPathPrefix = (): string =>
  appEnvironment.widgetImageCaptureReadPathPrefix ?? DEFAULT_WIDGET_IMAGE_CAPTURE_READ_PATH_PREFIX;

/** The app's widgets that draw a Google map (`AgentsEnvironment.googleMapWidgetNames`). */
export const getGoogleMapWidgetNames = (): readonly string[] => appEnvironment.googleMapWidgetNames ?? [];

/** The app's further widget click signal sources (`AgentsEnvironment.furtherWidgetClickSignalSources`). */
export const getFurtherWidgetClickSignalSources = (): readonly string[] =>
  appEnvironment.furtherWidgetClickSignalSources ?? [];

/** The canvases of the app's widgets (`AgentsEnvironment.furtherCanvasBehaviours`). */
export const getFurtherCanvasBehaviours = (): Readonly<Record<string, CanvasBehaviour>> =>
  appEnvironment.furtherCanvasBehaviours ?? {};

/** The name the owner knows the agent by (`AgentsEnvironment.agentDisplayName`). */
export const getAgentDisplayName = (): string => appEnvironment.agentDisplayName ?? translateText("the assistant");

/** Reports a running turn of an open chat (`AgentsEnvironment.reportRunningTurn`); returns the release. */
export const reportRunningTurn = (): (() => void) | undefined => appEnvironment.reportRunningTurn?.();

/** Reports a crashed widget (`AgentsEnvironment.reportWidgetCrash`). */
export const reportWidgetCrash = (widgetName: string, error: Error, errorInfo: ErrorInfo): void => {
  appEnvironment.reportWidgetCrash?.(widgetName, error, errorInfo);
};
