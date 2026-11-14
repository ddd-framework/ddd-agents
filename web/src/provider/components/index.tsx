import { useAgentsComponents } from "../AgentsProvider";
import type {
  AgentsButtonProps,
  AgentsCheckboxProps,
  AgentsDateInputProps,
  AgentsGlowFrameProps,
  AgentsIconProps,
  AgentsInteractiveSurfaceProps,
  AgentsLoadingSpinnerProps,
  AgentsMapMarkerProps,
  AgentsMapProps,
  AgentsModalLayerProps,
  AgentsProgressBarProps,
  AgentsRadioGroupProps,
  AgentsRangeSliderProps,
  AgentsSelectProps,
  AgentsSkeletonProps,
  AgentsSplitViewProps,
  AgentsStatusBadgeProps,
  AgentsTabsProps,
  AgentsTextAreaProps,
  AgentsTextInputProps,
  AgentsTextProps,
  AgentsTooltipProps,
  AgentsTrendDeltaProps,
  AgentsHeroRowProps,
  AgentsHeroValueProps,
  AgentsWidgetTextProps,
  AgentsSkeletonTextProps,
  AgentsStatusChipProps,
  AgentsSegmentBarProps,
  AgentsMarkdownProps,
  AgentsWidgetFeatureGateProps,
  AgentsConversationSearchResultsListProps,
} from "./types";

/**
 * The components package code imports. Each renders the app's replacement when the environment has one, else the
 * package default — package code never imports an app's component library.
 */
export const Text = (props: AgentsTextProps) => {
  const { Text: TextComponent } = useAgentsComponents();
  return <TextComponent {...props} />;
};

export const Button = (props: AgentsButtonProps) => {
  const { Button: ButtonComponent } = useAgentsComponents();
  return <ButtonComponent {...props} />;
};

export const Icon = (props: AgentsIconProps) => {
  const { Icon: IconComponent } = useAgentsComponents();
  return <IconComponent {...props} />;
};

export const InteractiveSurface = (props: AgentsInteractiveSurfaceProps) => {
  const { InteractiveSurface: InteractiveSurfaceComponent } = useAgentsComponents();
  return <InteractiveSurfaceComponent {...props} />;
};

export const Skeleton = (props: AgentsSkeletonProps) => {
  const { Skeleton: SkeletonComponent } = useAgentsComponents();
  return <SkeletonComponent {...props} />;
};

export const LoadingSpinner = (props: AgentsLoadingSpinnerProps) => {
  const { LoadingSpinner: LoadingSpinnerComponent } = useAgentsComponents();
  return <LoadingSpinnerComponent {...props} />;
};

export const Tooltip = (props: AgentsTooltipProps) => {
  const { Tooltip: TooltipComponent } = useAgentsComponents();
  return <TooltipComponent {...props} />;
};

export const GlowFrame = (props: AgentsGlowFrameProps) => {
  const { GlowFrame: GlowFrameComponent } = useAgentsComponents();
  return <GlowFrameComponent {...props} />;
};

export const ProgressBar = (props: AgentsProgressBarProps) => {
  const { ProgressBar: ProgressBarComponent } = useAgentsComponents();
  return <ProgressBarComponent {...props} />;
};

export const SplitView = (props: AgentsSplitViewProps) => {
  const { SplitView: SplitViewComponent } = useAgentsComponents();
  return <SplitViewComponent {...props} />;
};

export const TrendDelta = (props: AgentsTrendDeltaProps) => {
  const { TrendDelta: TrendDeltaComponent } = useAgentsComponents();
  return <TrendDeltaComponent {...props} />;
};

export const Checkbox = (props: AgentsCheckboxProps) => {
  const { Checkbox: CheckboxComponent } = useAgentsComponents();
  return <CheckboxComponent {...props} />;
};

export const TextInput = (props: AgentsTextInputProps) => {
  const { TextInput: TextInputComponent } = useAgentsComponents();
  return <TextInputComponent {...props} />;
};

export const TextArea = (props: AgentsTextAreaProps) => {
  const { TextArea: TextAreaComponent } = useAgentsComponents();
  return <TextAreaComponent {...props} />;
};

export const RangeSlider = (props: AgentsRangeSliderProps) => {
  const { RangeSlider: RangeSliderComponent } = useAgentsComponents();
  return <RangeSliderComponent {...props} />;
};

export const DateInput = (props: AgentsDateInputProps) => {
  const { DateInput: DateInputComponent } = useAgentsComponents();
  return <DateInputComponent {...props} />;
};

export const RadioGroup = (props: AgentsRadioGroupProps) => {
  const { RadioGroup: RadioGroupComponent } = useAgentsComponents();
  return <RadioGroupComponent {...props} />;
};

export const Select = (props: AgentsSelectProps) => {
  const { Select: SelectComponent } = useAgentsComponents();
  return <SelectComponent {...props} />;
};

export const Tabs = (props: AgentsTabsProps) => {
  const { Tabs: TabsComponent } = useAgentsComponents();
  return <TabsComponent {...props} />;
};

export const StatusBadge = (props: AgentsStatusBadgeProps) => {
  const { StatusBadge: StatusBadgeComponent } = useAgentsComponents();
  return <StatusBadgeComponent {...props} />;
};

export const Map = (props: AgentsMapProps) => {
  const { Map: MapComponent } = useAgentsComponents();
  return <MapComponent {...props} />;
};

export const MapMarker = (props: AgentsMapMarkerProps) => {
  const { MapMarker: MapMarkerComponent } = useAgentsComponents();
  return <MapMarkerComponent {...props} />;
};

export const ModalLayer = (props: AgentsModalLayerProps) => {
  const { ModalLayer: ModalLayerComponent } = useAgentsComponents();
  return <ModalLayerComponent {...props} />;
};

export const HeroRow = (props: AgentsHeroRowProps) => {
  const { HeroRow: HeroRowComponent } = useAgentsComponents();
  return <HeroRowComponent {...props} />;
};

export const HeroValue = (props: AgentsHeroValueProps) => {
  const { HeroValue: HeroValueComponent } = useAgentsComponents();
  return <HeroValueComponent {...props} />;
};

export const WidgetText = (props: AgentsWidgetTextProps) => {
  const { WidgetText: WidgetTextComponent } = useAgentsComponents();
  return <WidgetTextComponent {...props} />;
};

export const SkeletonText = (props: AgentsSkeletonTextProps) => {
  const { SkeletonText: SkeletonTextComponent } = useAgentsComponents();
  return <SkeletonTextComponent {...props} />;
};

export const StatusChip = (props: AgentsStatusChipProps) => {
  const { StatusChip: StatusChipComponent } = useAgentsComponents();
  return <StatusChipComponent {...props} />;
};

export const SegmentBar = (props: AgentsSegmentBarProps) => {
  const { SegmentBar: SegmentBarComponent } = useAgentsComponents();
  return <SegmentBarComponent {...props} />;
};

export const Markdown = (props: AgentsMarkdownProps) => {
  const { Markdown: MarkdownComponent } = useAgentsComponents();
  return <MarkdownComponent {...props} />;
};

export const WidgetFeatureGate = (props: AgentsWidgetFeatureGateProps) => {
  const { WidgetFeatureGate: WidgetFeatureGateComponent } = useAgentsComponents();
  return <WidgetFeatureGateComponent {...props} />;
};

export const ConversationSearchResultsList = (props: AgentsConversationSearchResultsListProps) => {
  const { ConversationSearchResultsList: ConversationSearchResultsListComponent } = useAgentsComponents();
  return <ConversationSearchResultsListComponent {...props} />;
};

export type * from "./types";
export { AGENTS_ICON_NAMES, isAgentsIconName } from "./icon-names";
