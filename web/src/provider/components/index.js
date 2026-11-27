import { useAgentsComponents } from "../AgentsProvider";
/**
 * The components package code imports. Each renders the app's replacement when the environment has one, else the
 * package default — package code never imports an app's component library.
 */
export const Text = (props) => {
    const { Text: TextComponent } = useAgentsComponents();
    return <TextComponent {...props}/>;
};
export const Button = (props) => {
    const { Button: ButtonComponent } = useAgentsComponents();
    return <ButtonComponent {...props}/>;
};
export const Icon = (props) => {
    const { Icon: IconComponent } = useAgentsComponents();
    return <IconComponent {...props}/>;
};
export const InteractiveSurface = (props) => {
    const { InteractiveSurface: InteractiveSurfaceComponent } = useAgentsComponents();
    return <InteractiveSurfaceComponent {...props}/>;
};
export const Skeleton = (props) => {
    const { Skeleton: SkeletonComponent } = useAgentsComponents();
    return <SkeletonComponent {...props}/>;
};
export const LoadingSpinner = (props) => {
    const { LoadingSpinner: LoadingSpinnerComponent } = useAgentsComponents();
    return <LoadingSpinnerComponent {...props}/>;
};
export const Tooltip = (props) => {
    const { Tooltip: TooltipComponent } = useAgentsComponents();
    return <TooltipComponent {...props}/>;
};
export const GlowFrame = (props) => {
    const { GlowFrame: GlowFrameComponent } = useAgentsComponents();
    return <GlowFrameComponent {...props}/>;
};
export const ProgressBar = (props) => {
    const { ProgressBar: ProgressBarComponent } = useAgentsComponents();
    return <ProgressBarComponent {...props}/>;
};
export const SplitView = (props) => {
    const { SplitView: SplitViewComponent } = useAgentsComponents();
    return <SplitViewComponent {...props}/>;
};
export const TrendDelta = (props) => {
    const { TrendDelta: TrendDeltaComponent } = useAgentsComponents();
    return <TrendDeltaComponent {...props}/>;
};
export const Checkbox = (props) => {
    const { Checkbox: CheckboxComponent } = useAgentsComponents();
    return <CheckboxComponent {...props}/>;
};
export const TextInput = (props) => {
    const { TextInput: TextInputComponent } = useAgentsComponents();
    return <TextInputComponent {...props}/>;
};
export const TextArea = (props) => {
    const { TextArea: TextAreaComponent } = useAgentsComponents();
    return <TextAreaComponent {...props}/>;
};
export const RangeSlider = (props) => {
    const { RangeSlider: RangeSliderComponent } = useAgentsComponents();
    return <RangeSliderComponent {...props}/>;
};
export const DateInput = (props) => {
    const { DateInput: DateInputComponent } = useAgentsComponents();
    return <DateInputComponent {...props}/>;
};
export const RadioGroup = (props) => {
    const { RadioGroup: RadioGroupComponent } = useAgentsComponents();
    return <RadioGroupComponent {...props}/>;
};
export const Select = (props) => {
    const { Select: SelectComponent } = useAgentsComponents();
    return <SelectComponent {...props}/>;
};
export const Tabs = (props) => {
    const { Tabs: TabsComponent } = useAgentsComponents();
    return <TabsComponent {...props}/>;
};
export const StatusBadge = (props) => {
    const { StatusBadge: StatusBadgeComponent } = useAgentsComponents();
    return <StatusBadgeComponent {...props}/>;
};
export const Map = (props) => {
    const { Map: MapComponent } = useAgentsComponents();
    return <MapComponent {...props}/>;
};
export const MapMarker = (props) => {
    const { MapMarker: MapMarkerComponent } = useAgentsComponents();
    return <MapMarkerComponent {...props}/>;
};
export const ModalLayer = (props) => {
    const { ModalLayer: ModalLayerComponent } = useAgentsComponents();
    return <ModalLayerComponent {...props}/>;
};
export const HeroRow = (props) => {
    const { HeroRow: HeroRowComponent } = useAgentsComponents();
    return <HeroRowComponent {...props}/>;
};
export const HeroValue = (props) => {
    const { HeroValue: HeroValueComponent } = useAgentsComponents();
    return <HeroValueComponent {...props}/>;
};
export const WidgetText = (props) => {
    const { WidgetText: WidgetTextComponent } = useAgentsComponents();
    return <WidgetTextComponent {...props}/>;
};
export const SkeletonText = (props) => {
    const { SkeletonText: SkeletonTextComponent } = useAgentsComponents();
    return <SkeletonTextComponent {...props}/>;
};
export const StatusChip = (props) => {
    const { StatusChip: StatusChipComponent } = useAgentsComponents();
    return <StatusChipComponent {...props}/>;
};
export const SegmentBar = (props) => {
    const { SegmentBar: SegmentBarComponent } = useAgentsComponents();
    return <SegmentBarComponent {...props}/>;
};
export const Markdown = (props) => {
    const { Markdown: MarkdownComponent } = useAgentsComponents();
    return <MarkdownComponent {...props}/>;
};
export const WidgetFeatureGate = (props) => {
    const { WidgetFeatureGate: WidgetFeatureGateComponent } = useAgentsComponents();
    return <WidgetFeatureGateComponent {...props}/>;
};
export const ConversationSearchResultsList = (props) => {
    const { ConversationSearchResultsList: ConversationSearchResultsListComponent } = useAgentsComponents();
    return <ConversationSearchResultsListComponent {...props}/>;
};
export { AGENTS_ICON_NAMES, isAgentsIconName } from "./icon-names";
