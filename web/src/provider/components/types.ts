/// <reference types="google.maps" preserve="true" />
import type { ComponentType, CSSProperties, MouseEvent, ReactElement, ReactNode } from "react";
import type { Components as MarkdownComponents } from "react-markdown";
import type { PluggableList } from "unified";

/**
 * The component vocabulary of the package. These prop types are the contract between package code and the components
 * that render it: the package ships a default for each, an app may replace any of them through the environment.
 * The vocabulary is the package's own; an app's replacement translates it to its component library.
 */

/** What a piece of text is, from largest to smallest. */
export type AgentsTextRole = "title" | "intro" | "body" | "help" | "caption";
export type AgentsTextWeight = "regular" | "medium" | "bold";
export type AgentsTextReplacements = Record<string, string | number | ReactNode>;

export interface AgentsTextProps {
  children?: ReactNode;
  role?: AgentsTextRole;
  weight?: AgentsTextWeight;
  /** A CSS colour, or the name of a custom property (`--token`), which stands for `var(--token)`. */
  color?: string;
  /**
   * A string child is a text key: the English source text, looked up through the environment's `texts.translate`.
   * `false` renders the string as it is (text that is data, not interface copy).
   */
  translate?: boolean;
  /** `%name%` placeholders of the key. Values are plain text or elements, never markup strings. */
  replacements?: AgentsTextReplacements;
  /** Plural selector of the key. */
  context?: "one" | "other";
  /** Cuts the text at the end of its line with an ellipsis. */
  ellipsis?: boolean;
  display?: "block" | "inline";
  textAlign?: "center";
  textWrap?: "wrap" | "nowrap";
  /** Keep line breaks of the string. */
  allowNewLines?: boolean;
  /** Announces the text as a heading of this level to assistive technology; the look stays the role's. */
  headingLevel?: number;
  /** Hides the text from assistive technology. */
  ariaHidden?: boolean;
  className?: string;
  testId?: string;
}

export type AgentsButtonVariant = "default" | "primary" | "muted" | "shimmer";
export type AgentsButtonSize = "small" | "medium" | "extraLarge";

export interface AgentsButtonProps {
  /** The label: a text key, translated unless `translate` is false. */
  children?: string;
  /** Default `primary`. */
  variant?: AgentsButtonVariant;
  size?: AgentsButtonSize;
  onClick?: (event: MouseEvent) => void;
  disabled?: boolean;
  /** Shows the button as busy (a running action). */
  isLoading?: boolean;
  /** A pill-shaped button. */
  rounded?: boolean;
  /** A `muted` toggle button that is switched on. */
  pressed?: boolean;
  /** A `muted` button on a photo or a dark surface. */
  onDark?: boolean;
  /** A `muted` button with a blurred backdrop behind its fill. */
  blurred?: boolean;
  /** The colours for a dark or coloured surface. */
  inverted?: boolean;
  icon?: AgentsIconName;
  iconPosition?: "left" | "right";
  htmlType?: "button" | "submit" | "reset";
  fullWidth?: boolean;
  /** A long label wraps onto further lines instead of staying on one. */
  wrapsLabel?: boolean;
  translate?: boolean;
  replacements?: AgentsTextReplacements;
  className?: string;
  testId?: string;
  ariaLabel?: string;
}

/** Icon names are the package's list (`icon-names.ts`); an app's replacement maps each to its icon set. */
export type AgentsIconName = import("./icon-names").AgentsIconName;
export type AgentsIconSize = "small" | "large";

export interface AgentsIconProps {
  /** A package icon. */
  name?: AgentsIconName;
  /** An icon named by the app's own data (an icon class a record carries); used when `name` is not set. */
  hostIconName?: string;
  size?: AgentsIconSize;
  /** An exact size in px; wins over `size`. */
  pixelSize?: number;
  color?: string;
  className?: string;
  style?: CSSProperties;
}

/** A surface that draws the hover state over its content (the content keeps the click and the focus). */
export interface AgentsInteractiveSurfaceProps {
  children?: ReactNode;
  hoverable?: boolean;
  className?: string;
  style?: CSSProperties;
  testId?: string;
}

/** A placeholder block shown while content loads. */
export interface AgentsSkeletonProps {
  width?: string | number;
  height?: string | number;
  borderRadius?: string | number;
  className?: string;
  style?: CSSProperties;
}

/** A spinner for content that is loading and has no shape yet. */
export type AgentsLoadingSpinnerProps = Record<string, never>;

/** A short explanation shown while the pointer rests on its child. */
export interface AgentsTooltipProps {
  children: ReactElement;
  /** A text key; nothing is shown while it is undefined. */
  text?: string;
  replacements?: AgentsTextReplacements;
  placement?: "top";
  /** Light text on a dark panel. */
  dark?: boolean;
  /** The child sits in a wrapper element that receives the pointer. */
  wrapsChild?: boolean;
}

/** An animated glowing border around its content. */
export interface AgentsGlowFrameProps {
  children?: ReactNode;
  className?: string;
  /** The colour of the glow instead of its gradient. */
  color?: string;
  borderRadius?: number;
  borderWidth?: number;
  blurWidth?: number;
}

export interface AgentsProgressBarProps {
  /** The progress in percent. */
  value?: number;
  height?: string;
  color?: string;
  /** Moving without a known progress. */
  indeterminate?: boolean;
}

export interface AgentsSplitViewPane {
  content: ReactNode;
  /** The pane's width while the side pane is closed (main) or open (side), as a CSS length. */
  width: string;
  /** The pane's width while the side pane is open. */
  narrowedWidth?: string;
  background?: string;
}

/** Two panes side by side; the side pane slides in over the right edge and covers the screen below a width. */
export interface AgentsSplitViewProps {
  isSideOpen: boolean;
  /** Below this viewport width the open side pane covers the main pane. */
  coversBelowWidth?: number;
  main: AgentsSplitViewPane;
  side: AgentsSplitViewPane;
  /** The toggle button on the divider. */
  sideToggle?: { onToggle: () => void; verticalPositionPercent?: number };
}

/** The change of a figure as an arrow and a signed amount, coloured by whether it is good. */
export interface AgentsTrendDeltaProps {
  current: number;
  previous?: number;
  higherIsBetter?: boolean;
  /** Formats the amount of the change (always positive). */
  format?: (magnitude: number) => string;
}

export interface AgentsCheckboxProps {
  checked?: boolean;
  disabled?: boolean;
  label?: ReactNode;
  translate?: boolean;
  /** A long word in the label breaks onto the next line. */
  breaksLongWords?: boolean;
  onChange?: () => void;
  testId?: string;
}

export interface AgentsTextInputProps {
  id?: string;
  value?: string;
  type?: "text" | "number" | "email";
  /** A visible label above the field (a text key); without one the field is unlabelled. */
  label?: string;
  placeholder?: string;
  maxLength?: number;
  onChange?: (text: string) => void;
  testId?: string;
}

export interface AgentsTextAreaProps {
  id?: string;
  value?: string;
  /** A visible label above the field (a text key); without one the field is unlabelled. */
  label?: string;
  placeholder?: string;
  maxLength?: number;
  rows?: number;
  minRows?: number;
  maxRows?: number;
  showsCounter?: boolean;
  autoFocus?: boolean;
  onChange?: (text: string) => void;
  testId?: string;
}

export interface AgentsRangeSliderProps {
  value: number;
  min: number;
  max: number;
  step?: number;
  /** The owner moves the thumb. */
  onMove?: (value: number) => void;
  /** The owner releases the thumb. */
  onRelease?: (value: number) => void;
}

export interface AgentsDateInputProps {
  /** The picked day as a timestamp in milliseconds. */
  value?: number;
  minDate?: number;
  maxDate?: number;
  /** The date pattern locale, e.g. `de_DE`. */
  locale: string;
  onChange?: (value: number | null | undefined) => void;
}

export interface AgentsChoiceOption {
  value: string;
  label: ReactNode;
  disabled?: boolean;
}

export interface AgentsRadioGroupProps {
  name: string;
  value: string;
  options: AgentsChoiceOption[];
  direction?: "row" | "column";
  onChange: (value: string) => void;
}

export interface AgentsSelectOption {
  value: string;
  /** The text of the option, shown as it is. */
  label: string;
  description?: string;
  disabled?: boolean;
}

export interface AgentsSelectProps {
  id?: string;
  value: string;
  options: AgentsSelectOption[];
  placeholder?: string;
  onChange: (value: string) => void;
  testId?: string;
}

export interface AgentsTabsOption extends AgentsChoiceOption {
  testId?: string;
}

/** A few short choices side by side, one of them picked. */
export interface AgentsTabsProps {
  options: AgentsTabsOption[];
  value?: string;
  onChange: (value: string) => void;
  ariaLabel?: string;
}

export type AgentsStatusBadgeStatus = "neutral" | "info" | "success" | "warning" | "danger";

export interface AgentsStatusBadgeProps {
  status: AgentsStatusBadgeStatus;
  /** A text key, translated unless `translate` is false. */
  text: string;
  translate?: boolean;
  hideIcon?: boolean;
  size?: "small";
  className?: string;
}

export interface AgentsMapPosition {
  lat: number;
  lng: number;
}

/** An interactive map. The app supplies it; the package default only says that there is no map. */
export interface AgentsMapProps {
  children?: ReactNode;
  /** The map script is loaded. */
  isLoaded: boolean;
  languageCode?: string;
  center?: AgentsMapPosition;
  zoom?: number;
  options?: google.maps.MapOptions;
  onLoad?: (map: google.maps.Map) => void;
  className?: string;
  testId?: string;
}

/** A marker on the map whose look is its children. */
export interface AgentsMapMarkerProps {
  id: string;
  isLoaded: boolean;
  position: AgentsMapPosition;
  zIndex?: number;
  title?: string;
  children?: ReactNode;
}

/** The layer modals open into (an image gallery). */
export interface AgentsModalLayerProps {
  children?: ReactNode;
  /** Renders the element modals mount into, before the children. */
  includesModalRoot?: boolean;
}

/** The row that holds a hero figure and what sits beside it. */
export interface AgentsHeroRowProps {
  children: ReactNode;
  className?: string;
}

/** The large figure of a KPI (`2.4K`, `87 %`); a figure too wide for its row shrinks instead of overflowing. */
export interface AgentsHeroValueProps {
  children: ReactNode;
  color?: string;
}

/** The type scale of the widgets, from the hero figure down to the micro label. */
export type AgentsWidgetTextVariant =
  | "hero"
  | "headline"
  | "gaugeValue"
  | "value"
  | "caption"
  | "label"
  | "micro"
  | "inherit";
export type AgentsWidgetTextTone = "default" | "light" | "cta";

/** A text of the widget type scale. A string child is a text key unless `translate` is false. */
export interface AgentsWidgetTextProps {
  children: ReactNode;
  /** Default `inherit`: size, weight and colour of the parent. */
  variant?: AgentsWidgetTextVariant;
  tone?: AgentsWidgetTextTone;
  /** A CSS colour for a data-driven tone; wins over `tone`. */
  color?: string;
  weight?: AgentsTextWeight;
  uppercase?: boolean;
  /** Digits of equal width. */
  tabular?: boolean;
  strikethrough?: boolean;
  inline?: boolean;
  translate?: boolean;
  context?: "one" | "other";
  replacements?: Record<string, string | number | null | undefined>;
  className?: string;
  testId?: string;
}

/** A placeholder line in the place of a text that is still loading, sized in `em`. */
export interface AgentsSkeletonTextProps {
  width?: string | number;
  height?: string | number;
  className?: string;
}

export type AgentsStatusChipTone = "success" | "warning" | "danger" | "neutral" | "info" | "error";

/** A short status in a coloured chip. */
export interface AgentsStatusChipProps {
  tone?: AgentsStatusChipTone;
  /** A text key, or — with `translate={false}` — a plain text the caller passed through `escapePlainTextForText`. */
  children: string;
  /** For tight rows: no icon, the text wraps inside the chip. */
  compact?: boolean;
  translate?: boolean;
}

export interface AgentsSegment {
  value: number;
  color: string;
  /** Striped fill (a pending part). */
  pattern?: "striped";
  /** Faded fill (a stale part). */
  muted?: boolean;
}

/** A horizontal bar split into proportional parts; a part of zero collapses. */
export interface AgentsSegmentBarProps {
  segments: AgentsSegment[];
  height?: number;
  className?: string;
  loading?: boolean;
  testId?: string;
}

/** The elements a Markdown text renders, by tag or by widget name. */
export type AgentsMarkdownComponents = MarkdownComponents &
  Record<string, ComponentType<Record<string, unknown> & { children?: ReactNode }>>;

/** A Markdown text (GitHub flavour). */
export interface AgentsMarkdownProps {
  markdown: string;
  color?: string;
  /**
   * Headings and paragraphs render through the app's text component (its type scale) instead of the Markdown
   * stylesheet, and links open in a new tab.
   */
  usesTextComponent?: boolean;
  /** Whether a text component translates the text (only with `usesTextComponent`). */
  translate?: boolean;
  remarkPlugins?: PluggableList;
  rehypePlugins?: PluggableList;
  components?: AgentsMarkdownComponents;
}

/** Shown in place of a widget whose requirement the owner may lack: the widget, a placeholder or an upgrade offer. */
export interface AgentsWidgetFeatureGateProps {
  /** The app's requirement name the widget declares (opaque to the package). */
  featureFlag: string;
  /** The widget's own upgrade offer, if it declares one. */
  UpsellTrigger?: ComponentType<{ flag: unknown }>;
  /** The widget's raw agent space key attribute: the gate checks the requirements of another agent space. */
  agentSpaceKeyAttribute?: string | number;
  children: ReactNode;
}

/** One matched message of a conversation search. */
export interface AgentsConversationSearchHit {
  role?: string;
  excerpt?: string;
  indexInConversation?: number;
  messageId?: number;
  created?: string;
  updated?: string;
  dateTime?: string;
}

/** One conversation with its matched messages. */
export interface AgentsConversationSearchGroup {
  conversationId?: number;
  title?: string;
  summary?: string;
  created?: string;
  updated?: string;
  dateTime?: string;
  hits: AgentsConversationSearchHit[];
}

/** The grouped results of a search over the owner's conversations. */
export interface AgentsConversationSearchResultsListProps {
  groups: AgentsConversationSearchGroup[];
  query: string;
  /** Opens the conversation a group or a hit belongs to (and scrolls to the hit's message). */
  onOpenConversation: (conversationId: number, messageId?: number) => void;
}

/**
 * Every component package code renders that an app may replace. Each member has a package default
 * (`default-components.tsx`). Members are added only together with their default.
 */
export interface AgentsComponents {
  Text: ComponentType<AgentsTextProps>;
  Button: ComponentType<AgentsButtonProps>;
  Icon: ComponentType<AgentsIconProps>;
  InteractiveSurface: ComponentType<AgentsInteractiveSurfaceProps>;
  Skeleton: ComponentType<AgentsSkeletonProps>;
  LoadingSpinner: ComponentType<AgentsLoadingSpinnerProps>;
  Tooltip: ComponentType<AgentsTooltipProps>;
  GlowFrame: ComponentType<AgentsGlowFrameProps>;
  ProgressBar: ComponentType<AgentsProgressBarProps>;
  SplitView: ComponentType<AgentsSplitViewProps>;
  TrendDelta: ComponentType<AgentsTrendDeltaProps>;
  Checkbox: ComponentType<AgentsCheckboxProps>;
  TextInput: ComponentType<AgentsTextInputProps>;
  TextArea: ComponentType<AgentsTextAreaProps>;
  RangeSlider: ComponentType<AgentsRangeSliderProps>;
  DateInput: ComponentType<AgentsDateInputProps>;
  RadioGroup: ComponentType<AgentsRadioGroupProps>;
  Select: ComponentType<AgentsSelectProps>;
  Tabs: ComponentType<AgentsTabsProps>;
  StatusBadge: ComponentType<AgentsStatusBadgeProps>;
  Map: ComponentType<AgentsMapProps>;
  MapMarker: ComponentType<AgentsMapMarkerProps>;
  ModalLayer: ComponentType<AgentsModalLayerProps>;
  HeroRow: ComponentType<AgentsHeroRowProps>;
  HeroValue: ComponentType<AgentsHeroValueProps>;
  WidgetText: ComponentType<AgentsWidgetTextProps>;
  SkeletonText: ComponentType<AgentsSkeletonTextProps>;
  StatusChip: ComponentType<AgentsStatusChipProps>;
  SegmentBar: ComponentType<AgentsSegmentBarProps>;
  Markdown: ComponentType<AgentsMarkdownProps>;
  WidgetFeatureGate: ComponentType<AgentsWidgetFeatureGateProps>;
  ConversationSearchResultsList: ComponentType<AgentsConversationSearchResultsListProps>;
}
