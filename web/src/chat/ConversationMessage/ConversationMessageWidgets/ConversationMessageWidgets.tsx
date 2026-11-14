import { Button } from "../../../provider/components";
import type { ChatAssistantCategory, ChatAssistantIdentity } from "../../chat-assistant-identity";
import React, { memo, useCallback, useEffect, useRef, useState } from "react";
import styles from "./ConversationMessageWidgets.module.scss";
import { useTypewriter } from "./hooks/useTypewriter";
import { useStreamingText } from "./hooks/useStreamingText";
import { useFreshMessageBlockReveal } from "./hooks/useFreshMessageBlockReveal";
import { ConversationMessageHeader } from "./ConversationMessageHeader/ConversationMessageHeader";
import { ConversationMarkdown } from "../../../renderer/ConversationMarkdown";
import { copyRichToClipboard } from "./copyRichToClipboard";
import { normalizeServerMessageToMarkdown } from "../../normalize-server-message-to-markdown";
import { stripPreCallMessageMarker } from "../../strip-pre-call-message-marker";
import { useAgentsEnvironment } from "../../../provider/AgentsProvider";

export interface ConversationMessageWidgetsProps {
  aiPromptExtensionCategory?: ChatAssistantCategory;
  aiPromptExtension?: ChatAssistantIdentity;
  eventSource?: EventSource;
  message?: unknown;
  /**
   * The reply's widgets may be used (buttons, forms, hydration refresh): a live-delivered reply, and the newest reply
   * after a reload. Forwarded to every widget as `interactive` (+ the deprecated alias `fresh`).
   */
  interactive?: boolean;
  /**
   * The reply was delivered live right now — never after a reload. Forwarded to every widget as `justDelivered`, the
   * only gate for one-shot side effects (canvas open, draft creation), and the gate of the block reveal.
   */
  justDelivered?: boolean;
  hideWidgets?: boolean;
  /** Id of the host message: once-only guard of the block reveal, and the identity of a canvas opened from it. */
  messageId?: string | number | null;
  /**
   * Hide the assistant header (icon + name + "Assistant") and the copy button.
   * Used by a host, which renders bare assistant messages.
   */
  hideAssistantMeta?: boolean;
  /** Opt-in staggered fade-in of this fresh reply's markdown blocks (see useFreshMessageBlockReveal). Default off. */
  enableFreshMessageBlockReveal?: boolean;
}

export const ConversationMessageWidgets = memo((props: ConversationMessageWidgetsProps) => {
  const {
    aiPromptExtensionCategory,
    aiPromptExtension,
    eventSource,
    message,
    interactive = false,
    justDelivered = false,
    hideWidgets = false,
    hideAssistantMeta = false,
    enableFreshMessageBlockReveal = false,
    messageId,
  } = props;
  const { MessageFooter } = useAgentsEnvironment();

  const [copied, setCopied] = useState(false);
  const timeoutRef = useRef<number | null>(null);
  const renderedRef = useRef<HTMLDivElement | null>(null);

  const { displayedText, appendText, finishTypewriter } = useTypewriter({
    speed: 200,
    commitIntervalMs: 100,
  });

  useEffect(() => {
    return () => {
      if (timeoutRef.current != null) window.clearTimeout(timeoutRef.current);
    };
  }, []);

  // Only stream when no static message is provided
  useStreamingText({
    eventSource: message != null ? undefined : eventSource,
    onTextChunk: appendText,
    onError: finishTypewriter,
    onDone: finishTypewriter,
  });

  // If a message is provided, render it directly; otherwise render streamed text
  // Static message → normalize (already strips the {preCallMessage} sentinel); live stream → strip it here too.
  const textToRender =
    message != null ? normalizeServerMessageToMarkdown(message) : stripPreCallMessageMarker(displayedText);

  // (The former "a fresh widget message closes the open canvas" layout effect is gone: a canvas is superseded only
  // by another canvas being opened — and a pinned canvas survives a widget reply.)

  // Fade the just-delivered reply's rendered blocks in (staggered in view, once on scroll below the fold). Static
  // live-delivered assistant replies only — never after a reload, and the streaming path (message == null) renders
  // progressively already.
  useFreshMessageBlockReveal({
    containerRef: renderedRef,
    enabled: enableFreshMessageBlockReveal && justDelivered && message != null,
    messageId,
  });

  const onCopy = useCallback(async () => {
    const fallbackText = textToRender || "";
    const plain = renderedRef.current?.innerText?.trim() || fallbackText;
    const html = renderedRef.current?.innerHTML || "";

    await copyRichToClipboard(plain, html);

    setCopied(true);
    if (timeoutRef.current != null) window.clearTimeout(timeoutRef.current);
    timeoutRef.current = window.setTimeout(() => setCopied(false), 2000);
  }, [textToRender]);

  return (
    <div className={styles.responseContainer}>
      {!hideAssistantMeta ? (
        <ConversationMessageHeader
          aiPromptExtensionCategory={aiPromptExtensionCategory}
          aiPromptExtension={aiPromptExtension}
        />
      ) : null}
      <div className={styles.responseMessage}>
        <div ref={renderedRef}>
          {/* TODO: move the markdown component into the component library */}
          <ConversationMarkdown
            markdown={textToRender}
            interactive={interactive}
            justDelivered={justDelivered}
            messageId={messageId ?? undefined}
            hideWidgets={hideWidgets}
          />
        </div>

        {!hideAssistantMeta ? (
          <Button
            variant="muted"
            icon={copied ? "check" : "duplicate"}
            onClick={onCopy}
            size="small"
          />
        ) : null}
      </div>

      {/* The app's footer under the message (for example an admin-only trace footer that gates itself). */}
      {MessageFooter ? <MessageFooter message={message} /> : null}
    </div>
  );
});
