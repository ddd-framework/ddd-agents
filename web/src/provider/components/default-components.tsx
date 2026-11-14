import { useMemo, useState } from "react";
import ReactMarkdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";
import type { PluggableList } from "unified";
import type {
  AgentsButtonProps,
  AgentsCheckboxProps,
  AgentsComponents,
  AgentsConversationSearchResultsListProps,
  AgentsHeroRowProps,
  AgentsHeroValueProps,
  AgentsMarkdownProps,
  AgentsSegmentBarProps,
  AgentsSkeletonTextProps,
  AgentsStatusBadgeStatus,
  AgentsStatusChipProps,
  AgentsStatusChipTone,
  AgentsWidgetFeatureGateProps,
  AgentsWidgetTextProps,
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
  AgentsTextReplacements,
  AgentsTooltipProps,
  AgentsTrendDeltaProps,
} from "./types";
import { useAgentsTexts } from "../AgentsProvider";
import { classNames } from "../class-names";

const TEXT_ELEMENT_BY_ROLE = {
  title: "h4",
  intro: "p",
  body: "span",
  help: "span",
  caption: "span",
} as const;

/** `--token` stands for `var(--token)`; any other value is a CSS colour. */
const readCssColor = (color: string | undefined): string | undefined =>
  color?.startsWith("--") ? `var(${color})` : color;

const readPlainReplacements = (replacements?: AgentsTextReplacements): Record<string, string | number> => {
  const plainReplacements: Record<string, string | number> = {};
  for (const [name, value] of Object.entries(replacements ?? {})) {
    if (typeof value === "string" || typeof value === "number") plainReplacements[name] = value;
  }
  return plainReplacements;
};

const useTranslatedText = (
  text: string | undefined,
  translate = true,
  replacements?: AgentsTextReplacements,
  context?: string,
): string | undefined => {
  const texts = useAgentsTexts();
  if (text === undefined) return undefined;
  return translate ? texts.translate(text, readPlainReplacements(replacements), context) : text;
};

/** The package's own text: an element per role, styled by `agents-text--*` classes of the package stylesheet. */
const DefaultText = (props: AgentsTextProps) => {
  const {
    children,
    role = "body",
    weight,
    color,
    translate = true,
    replacements,
    context,
    ellipsis,
    display,
    textAlign,
    textWrap,
    allowNewLines,
    headingLevel,
    ariaHidden,
    className,
    testId,
  } = props;
  const texts = useAgentsTexts();
  const Element = TEXT_ELEMENT_BY_ROLE[role];
  const content =
    typeof children === "string" && translate
      ? texts.translate(children, readPlainReplacements(replacements), context)
      : children;
  return (
    <Element
      data-testid={testId}
      role={headingLevel ? "heading" : undefined}
      aria-level={headingLevel}
      aria-hidden={ariaHidden || undefined}
      className={classNames(
        "agents-text",
        `agents-text--${role}`,
        weight && `agents-text--weight-${weight}`,
        ellipsis && "agents-text--ellipsis",
        allowNewLines && "agents-text--new-lines",
        className,
      )}
      style={{
        color: readCssColor(color),
        display,
        textAlign,
        whiteSpace: textWrap === "nowrap" || ellipsis ? "nowrap" : allowNewLines ? "pre-line" : undefined,
      }}
    >
      {content}
    </Element>
  );
};

const DefaultIcon = (props: AgentsIconProps) => {
  const { name, hostIconName, size, pixelSize, color, className, style } = props;
  const iconName = name ?? hostIconName;
  return (
    <span
      aria-hidden
      data-icon={iconName}
      className={classNames("agents-icon", size && `agents-icon--${size}`, className)}
      style={{ color: readCssColor(color), ...(pixelSize ? { width: pixelSize, height: pixelSize } : null), ...style }}
    />
  );
};

const DefaultButton = (props: AgentsButtonProps) => {
  const {
    children,
    variant = "primary",
    size = "medium",
    onClick,
    disabled,
    isLoading,
    rounded,
    pressed,
    onDark,
    blurred,
    inverted,
    icon,
    iconPosition = "left",
    htmlType = "button",
    fullWidth,
    wrapsLabel,
    translate,
    replacements,
    className,
    testId,
    ariaLabel,
  } = props;
  const label = useTranslatedText(children, translate, replacements);
  return (
    <button
      type={htmlType}
      aria-label={ariaLabel}
      aria-pressed={pressed}
      aria-busy={isLoading || undefined}
      disabled={disabled || isLoading}
      data-testid={testId}
      onClick={onClick}
      className={classNames(
        "agents-button",
        `agents-button--${variant}`,
        `agents-button--${size}`,
        rounded && "agents-button--rounded",
        pressed && "agents-button--pressed",
        onDark && "agents-button--on-dark",
        blurred && "agents-button--blurred",
        inverted && "agents-button--inverted",
        fullWidth && "agents-button--full-width",
        wrapsLabel && "agents-button--wraps-label",
        className,
      )}
    >
      {icon && iconPosition === "left" ? <DefaultIcon name={icon} size="small" /> : null}
      {label}
      {icon && iconPosition === "right" ? <DefaultIcon name={icon} size="small" /> : null}
    </button>
  );
};

const DefaultInteractiveSurface = (props: AgentsInteractiveSurfaceProps) => {
  const { children, hoverable, className, style, testId } = props;
  return (
    <div
      data-testid={testId}
      className={classNames("agents-interactive-surface", hoverable && "agents-interactive-surface--hoverable", className)}
      style={style}
    >
      {children}
    </div>
  );
};

const DefaultSkeleton = (props: AgentsSkeletonProps) => {
  const { width, height, borderRadius = 4, className, style } = props;
  return (
    <div
      aria-hidden
      className={classNames("agents-skeleton", className)}
      style={{ width, height, borderRadius, ...style }}
    />
  );
};

const DefaultLoadingSpinner = (_props: AgentsLoadingSpinnerProps) => (
  <span role="progressbar" aria-busy className="agents-loading-spinner" />
);

const DefaultTooltip = (props: AgentsTooltipProps) => {
  const { children, text, replacements, placement = "top", dark } = props;
  const [isShown, setIsShown] = useState(false);
  const shownText = useTranslatedText(text, true, replacements);
  return (
    <span
      className="agents-tooltip"
      style={{ position: "relative", display: "inline-flex" }}
      onMouseEnter={() => setIsShown(true)}
      onMouseLeave={() => setIsShown(false)}
      onFocus={() => setIsShown(true)}
      onBlur={() => setIsShown(false)}
    >
      {children}
      {shownText && isShown ? (
        <span
          role="tooltip"
          className={classNames("agents-tooltip__panel", `agents-tooltip__panel--${placement}`, dark && "agents-tooltip__panel--dark")}
          style={{ position: "absolute", bottom: "100%", left: "50%", transform: "translateX(-50%)" }}
        >
          {shownText}
        </span>
      ) : null}
    </span>
  );
};

const DefaultGlowFrame = (props: AgentsGlowFrameProps) => {
  const { children, className, color, borderRadius, borderWidth } = props;
  return (
    <div
      className={classNames("agents-glow-frame", className)}
      style={{ borderRadius, borderWidth, borderStyle: borderWidth ? "solid" : undefined, borderColor: color }}
    >
      {children}
    </div>
  );
};

const DefaultProgressBar = (props: AgentsProgressBarProps) => {
  const { value, height, color, indeterminate } = props;
  return (
    <progress
      className="agents-progress-bar"
      max={100}
      value={indeterminate ? undefined : value}
      style={{ height, accentColor: color, width: "100%" }}
    />
  );
};

const DefaultSplitView = (props: AgentsSplitViewProps) => {
  const { isSideOpen, main, side, sideToggle } = props;
  return (
    <div className="agents-split-view" style={{ display: "flex", width: "100%", height: "100%" }}>
      <div
        className="agents-split-view__main"
        style={{ width: isSideOpen ? (main.narrowedWidth ?? main.width) : main.width, background: main.background }}
      >
        {main.content}
      </div>
      {sideToggle ? (
        <button type="button" className="agents-split-view__toggle" onClick={sideToggle.onToggle} aria-expanded={isSideOpen} />
      ) : null}
      {isSideOpen ? (
        <div className="agents-split-view__side" style={{ width: side.narrowedWidth ?? side.width, background: side.background }}>
          {side.content}
        </div>
      ) : null}
    </div>
  );
};

const DefaultTrendDelta = (props: AgentsTrendDeltaProps) => {
  const { current, previous = 0, higherIsBetter = true, format } = props;
  const change = current - previous;
  const magnitude = Math.abs(change);
  const isGood = change === 0 ? undefined : (change > 0) === higherIsBetter;
  return (
    <span
      className={classNames(
        "agents-trend-delta",
        isGood === true && "agents-trend-delta--good",
        isGood === false && "agents-trend-delta--bad",
      )}
    >
      {change > 0 ? "▲ " : change < 0 ? "▼ " : ""}
      {format ? format(magnitude) : String(magnitude)}
    </span>
  );
};

const DefaultCheckbox = (props: AgentsCheckboxProps) => {
  const { checked, disabled, label, translate, onChange, testId } = props;
  const translatedLabel = useTranslatedText(typeof label === "string" ? label : undefined, translate);
  return (
    <label className="agents-checkbox" data-testid={testId}>
      <input type="checkbox" checked={checked ?? false} disabled={disabled} onChange={onChange} />
      {typeof label === "string" ? translatedLabel : label}
    </label>
  );
};

const DefaultTextInput = (props: AgentsTextInputProps) => {
  const { id, value, type = "text", label, placeholder, maxLength, onChange, testId } = props;
  const translatedLabel = useTranslatedText(label);
  return (
    <label className="agents-text-input">
      {translatedLabel ? <span className="agents-text-input__label">{translatedLabel}</span> : null}
      <input
        id={id}
        type={type}
        value={value ?? ""}
        placeholder={placeholder}
        maxLength={maxLength}
        data-testid={testId}
        onChange={(event) => onChange?.(event.target.value)}
      />
    </label>
  );
};

const DefaultTextArea = (props: AgentsTextAreaProps) => {
  const { id, value, label, placeholder, maxLength, rows, minRows, showsCounter, autoFocus, onChange, testId } = props;
  const translatedLabel = useTranslatedText(label);
  return (
    <label className="agents-text-area">
      {translatedLabel ? <span className="agents-text-area__label">{translatedLabel}</span> : null}
      <textarea
        id={id}
        value={value ?? ""}
        placeholder={placeholder}
        maxLength={maxLength}
        rows={rows ?? minRows}
        autoFocus={autoFocus}
        data-testid={testId}
        onChange={(event) => onChange?.(event.target.value)}
      />
      {showsCounter && maxLength ? (
        <span className="agents-text-area__counter">{`${(value ?? "").length} / ${maxLength}`}</span>
      ) : null}
    </label>
  );
};

const DefaultRangeSlider = (props: AgentsRangeSliderProps) => {
  const { value, min, max, step, onMove, onRelease } = props;
  return (
    <input
      type="range"
      className="agents-range-slider"
      value={value}
      min={min}
      max={max}
      step={step}
      onChange={(event) => onMove?.(Number(event.target.value))}
      onPointerUp={(event) => onRelease?.(Number(event.currentTarget.value))}
      onKeyUp={(event) => onRelease?.(Number(event.currentTarget.value))}
    />
  );
};

const toIsoDay = (timestamp: number | undefined): string | undefined => {
  if (timestamp === undefined) return undefined;
  const day = new Date(timestamp);
  const month = String(day.getMonth() + 1).padStart(2, "0");
  const dayOfMonth = String(day.getDate()).padStart(2, "0");
  return `${day.getFullYear()}-${month}-${dayOfMonth}`;
};

const DefaultDateInput = (props: AgentsDateInputProps) => {
  const { value, minDate, maxDate, onChange } = props;
  return (
    <input
      type="date"
      className="agents-date-input"
      value={toIsoDay(value) ?? ""}
      min={toIsoDay(minDate)}
      max={toIsoDay(maxDate)}
      onChange={(event) => {
        const [year, month, day] = event.target.value.split("-").map(Number);
        onChange?.(event.target.value ? new Date(year, month - 1, day).getTime() : null);
      }}
    />
  );
};

const DefaultRadioGroup = (props: AgentsRadioGroupProps) => {
  const { name, value, options, direction = "column", onChange } = props;
  return (
    <div className="agents-radio-group" style={{ display: "flex", flexDirection: direction }}>
      {options.map((option) => (
        <label key={option.value} className="agents-radio-group__option">
          <input
            type="radio"
            name={name}
            value={option.value}
            checked={option.value === value}
            disabled={option.disabled}
            onChange={() => onChange(option.value)}
          />
          {option.label}
        </label>
      ))}
    </div>
  );
};

const DefaultSelect = (props: AgentsSelectProps) => {
  const { id, value, options, placeholder, onChange, testId } = props;
  return (
    <select
      id={id}
      className="agents-select"
      value={value}
      data-testid={testId}
      onChange={(event) => onChange(event.target.value)}
    >
      {placeholder !== undefined ? (
        <option value="" disabled>
          {placeholder}
        </option>
      ) : null}
      {options.map((option) => (
        <option key={option.value} value={option.value} disabled={option.disabled} title={option.description}>
          {option.label}
        </option>
      ))}
    </select>
  );
};

const DefaultTabs = (props: AgentsTabsProps) => {
  const { options, value, onChange, ariaLabel } = props;
  return (
    <div role="tablist" aria-label={ariaLabel} className="agents-tabs">
      {options
        .filter((option) => !option.disabled)
        .map((option) => (
          <button
            key={option.value}
            type="button"
            role="tab"
            aria-selected={option.value === value}
            data-testid={option.testId}
            className={classNames("agents-tabs__tab", option.value === value && "agents-tabs__tab--selected")}
            onClick={() => onChange(option.value)}
          >
            {option.label}
          </button>
        ))}
    </div>
  );
};

const DefaultStatusBadge = (props: AgentsStatusBadgeProps) => {
  const { status, text, translate, size, className } = props;
  const shownText = useTranslatedText(text, translate);
  return (
    <span
      className={classNames(
        "agents-status-badge",
        `agents-status-badge--${status}`,
        size && `agents-status-badge--${size}`,
        className,
      )}
    >
      {shownText}
    </span>
  );
};

/** No map without the app's map: a placeholder that says so. */
const DefaultMap = (props: AgentsMapProps) => {
  const { className, testId } = props;
  const noMapText = useTranslatedText("No map available.");
  return (
    <div className={classNames("agents-map-placeholder", className)} data-testid={testId} data-map="none">
      {noMapText}
    </div>
  );
};

const DefaultMapMarker = (_props: AgentsMapMarkerProps) => null;

const DefaultModalLayer = (props: AgentsModalLayerProps) => {
  const { children } = props;
  return <>{children}</>;
};

const DefaultHeroRow = (props: AgentsHeroRowProps) => {
  const { children, className } = props;
  return (
    <div data-hero-row="" className={classNames("agents-hero-row", className)}>
      {children}
    </div>
  );
};

const DefaultHeroValue = (props: AgentsHeroValueProps) => {
  const { children, color } = props;
  return (
    <span data-hero="" data-hero-value="" className="agents-hero-value" style={{ color: readCssColor(color) }}>
      {children}
    </span>
  );
};

const DefaultWidgetText = (props: AgentsWidgetTextProps) => {
  const {
    children,
    variant = "inherit",
    tone,
    color,
    weight,
    uppercase,
    tabular,
    strikethrough,
    inline = variant === "inherit",
    translate = typeof children === "string",
    context,
    replacements,
    className,
    testId,
  } = props;
  const texts = useAgentsTexts();
  const plainReplacements: Record<string, string | number> = {};
  for (const [name, value] of Object.entries(replacements ?? {})) {
    if (value !== null && value !== undefined) plainReplacements[name] = value;
  }
  const content =
    typeof children === "string" && translate ? texts.translate(children, plainReplacements, context) : children;
  const Element = inline ? "span" : "div";
  return (
    <Element
      data-testid={testId}
      className={classNames(
        "agents-widget-text",
        `agents-widget-text--${variant}`,
        tone && !color && `agents-widget-text--${tone}`,
        weight && `agents-widget-text--weight-${weight}`,
        uppercase && "agents-widget-text--uppercase",
        tabular && "agents-widget-text--tabular",
        strikethrough && "agents-widget-text--strikethrough",
        className,
      )}
      style={color ? { color: readCssColor(color) } : undefined}
    >
      {content}
    </Element>
  );
};

const DefaultSkeletonText = (props: AgentsSkeletonTextProps) => {
  const { width = "4.5em", height = "1em", className } = props;
  return (
    <span
      aria-hidden
      className={classNames("agents-skeleton", className)}
      style={{ display: "inline-block", width, height, borderRadius: 999 }}
    />
  );
};

const STATUS_BADGE_STATUS_BY_CHIP_TONE: Record<AgentsStatusChipTone, AgentsStatusBadgeStatus> = {
  success: "success",
  warning: "warning",
  danger: "danger",
  neutral: "neutral",
  info: "info",
  error: "danger",
};

const DefaultStatusChip = (props: AgentsStatusChipProps) => {
  const { tone = "neutral", children, compact = false, translate = true } = props;
  return (
    <DefaultStatusBadge
      status={STATUS_BADGE_STATUS_BY_CHIP_TONE[tone]}
      text={children}
      translate={translate}
      size="small"
      hideIcon={compact || tone !== "error"}
    />
  );
};

const DefaultSegmentBar = (props: AgentsSegmentBarProps) => {
  const { segments, height = 6, className, loading = false, testId } = props;
  const total = segments.reduce((sum, segment) => sum + Math.max(0, segment.value), 0);
  return (
    <div
      className={classNames("agents-segment-bar", className)}
      style={{ display: "flex", height, borderRadius: height, overflow: "hidden" }}
      data-segment-bar=""
      data-testid={testId}
    >
      {total > 0 || loading
        ? segments.map((segment, segmentIndex) => (
            <div
              key={segmentIndex}
              style={{
                width: loading
                  ? `${100 / segments.length}%`
                  : `${(Math.max(0, segment.value) / total) * 100}%`,
                background: segment.color,
                opacity: segment.muted || loading ? 0.5 : undefined,
              }}
            />
          ))
        : null}
    </div>
  );
};

const NO_MARKDOWN_PLUGINS: PluggableList = [];

/** Drops an image without a source: streamed Markdown emits a partial `![alt](` that would fetch the page itself. */
const MarkdownImage: Components["img"] = (imageProps) => {
  const { src, node, ...otherProps } = imageProps;
  return src ? <img src={src} {...otherProps} /> : null;
};

const DefaultMarkdown = (props: AgentsMarkdownProps) => {
  const { markdown, color, remarkPlugins = NO_MARKDOWN_PLUGINS, rehypePlugins = NO_MARKDOWN_PLUGINS, components } = props;
  const markdownComponents = useMemo(() => ({ img: MarkdownImage, ...components }) as Components, [components]);
  const markdownRemarkPlugins = useMemo(() => [remarkGfm, ...remarkPlugins], [remarkPlugins]);
  return (
    <div style={{ color }} className="agents-markdown" data-markdown-blocks-root>
      <ReactMarkdown
        remarkPlugins={markdownRemarkPlugins}
        rehypePlugins={rehypePlugins}
        components={markdownComponents}
      >
        {markdown}
      </ReactMarkdown>
    </div>
  );
};

/** Without an app gate every widget is available. */
const DefaultWidgetFeatureGate = (props: AgentsWidgetFeatureGateProps) => {
  const { children } = props;
  return <>{children}</>;
};

const DefaultConversationSearchResultsList = (props: AgentsConversationSearchResultsListProps) => {
  const { groups, onOpenConversation } = props;
  return (
    <ul className="agents-conversation-search-results">
      {groups.map((group, groupIndex) => (
        <li key={group.conversationId ?? groupIndex} className="agents-conversation-search-results__group">
          <DefaultText role="body" weight="bold" translate={false}>
            {group.title ?? ""}
          </DefaultText>
          {group.hits.map((hit, hitIndex) => (
            <button
              key={hit.messageId ?? hitIndex}
              type="button"
              className="agents-conversation-search-results__hit"
              onClick={() => {
                if (group.conversationId !== undefined) onOpenConversation(group.conversationId, hit.messageId);
              }}
            >
              <DefaultText role="help" translate={false}>
                {hit.excerpt ?? ""}
              </DefaultText>
            </button>
          ))}
        </li>
      ))}
    </ul>
  );
};

/** The package default of every replaceable component. */
export const DEFAULT_AGENTS_COMPONENTS: AgentsComponents = {
  Text: DefaultText,
  Button: DefaultButton,
  Icon: DefaultIcon,
  InteractiveSurface: DefaultInteractiveSurface,
  Skeleton: DefaultSkeleton,
  LoadingSpinner: DefaultLoadingSpinner,
  Tooltip: DefaultTooltip,
  GlowFrame: DefaultGlowFrame,
  ProgressBar: DefaultProgressBar,
  SplitView: DefaultSplitView,
  TrendDelta: DefaultTrendDelta,
  Checkbox: DefaultCheckbox,
  TextInput: DefaultTextInput,
  TextArea: DefaultTextArea,
  RangeSlider: DefaultRangeSlider,
  DateInput: DefaultDateInput,
  RadioGroup: DefaultRadioGroup,
  Select: DefaultSelect,
  Tabs: DefaultTabs,
  StatusBadge: DefaultStatusBadge,
  Map: DefaultMap,
  MapMarker: DefaultMapMarker,
  ModalLayer: DefaultModalLayer,
  HeroRow: DefaultHeroRow,
  HeroValue: DefaultHeroValue,
  WidgetText: DefaultWidgetText,
  SkeletonText: DefaultSkeletonText,
  StatusChip: DefaultStatusChip,
  SegmentBar: DefaultSegmentBar,
  Markdown: DefaultMarkdown,
  WidgetFeatureGate: DefaultWidgetFeatureGate,
  ConversationSearchResultsList: DefaultConversationSearchResultsList,
};
