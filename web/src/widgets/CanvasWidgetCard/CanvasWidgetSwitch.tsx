import { type AgentsIconName } from "../../provider/components";
import { useWidgetImageRender } from "../../image-render/WidgetImageRenderContext";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import React, { ReactNode } from "react";
import { CanvasWidgetCard, CanvasWidgetCardCanvas } from "./CanvasWidgetCard";

export interface CanvasWidgetInlineRenderOptions {
  /**
   * True only when the inline render is the narrow-viewport preview inside the {@link CanvasWidgetCard}, above its
   * "Open in canvas" button, so the canvas holds the full content. False in the chat's inline branch and in email /
   * image renders, where no canvas exists and the inline render is the whole widget: never shorten it there.
   */
  isWidgetPreview: boolean;
}

export interface CanvasWidgetSwitchProps {
  /**
   * Agent hint that forces the branch. Normally OMITTED — the switch auto-picks by content size.
   * `"canvas"` forces the canvas; `"inline"` keeps the content inline regardless of size.
   */
  display?: "inline" | "canvas";
  /**
   * The per-widget content rule: true when the content is larger than fits comfortably inline (more items / a longer
   * answer than the widget's inline limit). Combined with `display` to decide whether this is a canvas widget. Ignored
   * when `display` is set. Canvas-only widgets pass `display="canvas"` instead of this.
   */
  exceedsInlineLimit?: boolean;
  /** Headline on the inline card (e.g. "Search results for 'X'"). Used only when it opens as a canvas. */
  title: string;
  /** `%token%` → value replacements for `title`, so an interpolated headline stays one stable translation key. */
  titleReplacements?: Record<string, string | number>;
  /** Optional second line on the card (e.g. "5 sources", a filter summary). Used only when it opens as a canvas. */
  subtitle?: string;
  /** `%token%` → value replacements for `subtitle`, so an interpolated second line stays one stable translation key. */
  subtitleReplacements?: Record<string, string | number>;
  /** False when `subtitle` is already resolved text (a business value, a singular/plural value). Default true. */
  translateSubtitle?: boolean;
  /** Optional leading icon on the card. Used only when it opens as a canvas. */
  icon?: AgentsIconName;
  /** True when the host message was delivered live right now — auto-opens the canvas once. Never true after a reload or on history replay. */
  justDelivered?: boolean;
  /** The canvas the card opens (see {@link CanvasWidgetCardProps.canvas}). */
  canvas?: CanvasWidgetCardCanvas;
  /**
   * The other agent space the content belongs to, when it is not the active one: the named card shows its chip (see
   * {@link CanvasWidgetCardProps.anotherAgentSpaceKey}). The inline render names it itself.
   */
  anotherAgentSpaceKey?: number;
  /**
   * The inline render of the widget. Shown directly in the chat when the content is small enough to stay inline, and
   * reused by the {@link CanvasWidgetCard} as the narrow-viewport inline render below its card. Called once per render.
   * A widget that shortens its content for the widget preview does so only when `isWidgetPreview` is true.
   */
  renderInline: (options: CanvasWidgetInlineRenderOptions) => ReactNode;
  /** The widget's root test id (`<kebab wire name>-widget`), handed to the {@link CanvasWidgetCard}. */
  testId?: string;
}

/**
 * The single switch shared by every canvas widget. It owns the one decision — is this a CANVAS widget at all: the
 * agent forced it (`display`) or the content exceeds the widget's inline limit. Small content renders inline in the
 * chat. A canvas widget hands off to {@link CanvasWidgetCard}, which itself decides the viewport-dependent shape: on a
 * wide viewport a clickable card (opens the side split), on a narrow viewport the inline render plus an "Open in
 * canvas" CTA (opens over the chat). Centralising this here keeps all widgets consistent and free of the branch.
 */
export const CanvasWidgetSwitch = ({
  display,
  exceedsInlineLimit = false,
  title,
  titleReplacements,
  subtitle,
  subtitleReplacements,
  translateSubtitle,
  icon,
  justDelivered = false,
  canvas,
  anotherAgentSpaceKey,
  renderInline,
  testId,
}: CanvasWidgetSwitchProps) => {
  const capture = useWidgetImageRender();
  const isEmailRender = useIsEmailWidgetRender();
  if (isEmailRender || capture !== null) return <>{renderInline({ isWidgetPreview: false })}</>;

  const isCanvasWidget = display === "canvas" || (display !== "inline" && exceedsInlineLimit);

  if (!isCanvasWidget) return <>{renderInline({ isWidgetPreview: false })}</>;

  return (
    <CanvasWidgetCard
      title={title}
      titleReplacements={titleReplacements}
      subtitle={subtitle}
      subtitleReplacements={subtitleReplacements}
      translateSubtitle={translateSubtitle}
      icon={icon}
      justDelivered={justDelivered}
      canvas={canvas}
      anotherAgentSpaceKey={anotherAgentSpaceKey}
      preview={renderInline({ isWidgetPreview: true })}
      testId={testId}
    />
  );
};
