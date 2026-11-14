import { Button, GlowFrame, Text, Tooltip } from "../../provider/components";
import React, {
  useRef,
  useState,
  KeyboardEvent,
  useEffect,
  useImperativeHandle,
  useLayoutEffect,
  useMemo,
} from "react";
import styles from "./ConversationMessageInput.module.scss";
import type { ConversationAttachmentReference } from "../../contract/conversation";
import { SendMessageHandler } from "../conversation-send-message";
import { translateText } from "../../provider/environment";
import { classNames } from "../../provider/class-names";
import { ConversationComposerWidget } from "../../renderer/conversation-composer-widget";
import { buildMessageTextWithComposerWidgets } from "../../renderer/composer-widget-fence";
import { conversationWidgetRegistry } from "../../renderer/conversationWidgetRegistry";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetContext";

// Half the one-line compact input (8px padding + the 40px send row + 8px): fully round at rest, and the same corners
// as it grows with the text. The glow and the input share it through --ai-border-radius.
const COMPACT_INPUT_RADIUS_PX = 28;

/**
 * Cap on the message a user can send. Covers the longest legitimate business
 * inputs (pasted homepage copy, article drafts, menus ≈ 8k chars) while blocking
 * accidental huge dumps. Typing and pasting past it stay possible — the text is
 * never trimmed — but sending is blocked until the user edits back under the cap.
 */
export const MAX_MESSAGE_LENGTH = 10000;

/** The character counter stays hidden until the message approaches the cap. */
export const CHARACTER_COUNTER_SHOWN_FROM_LENGTH = Math.floor(MAX_MESSAGE_LENGTH * 0.9);

/**
 * Imperative handle so a host-owned control (e.g. the agent "+" menu's "Generate image" entry)
 * can put text into the input without owning the contentEditable itself.
 */
export interface ConversationMessageInputHandle {
  /** Replaces the input's text and focuses the caret at the end. */
  insertTextIntoInput: (text: string) => void;
}

export interface ConversationMessageInputProps {
  /**
   * Callback when a message is submitted. `options.attachments` echoes `outgoingAttachmentImages`
   * when the host provided any — ConversationMessageInput itself never builds attachments, it only rides them along.
   */
  onSendMessage?: SendMessageHandler;

  /**
   * Placeholder text for the input
   */
  placeholder?: string;

  /**
   * Whether the input is disabled
   */
  disabled?: boolean;

  /**
   * Theme color for the input
   */
  themeColor?: string;

  /**
   * One-line form: text and send button on the same row inside a pill, growing with the text. The agent dashboard's
   * input; the chat keeps the full form.
   */
  compact?: boolean;

  /**
   * Compact only: shrunk to a small glowing pill showing one line, the placeholder or the start of a draft, its buttons
   * hidden but kept (the agent dashboard while the page is scrolled). The host expands it again on hover, focus or at the
   * top of the page.
   */
  collapsed?: boolean;

  /**
   * Compact only: called with the width in px the collapsed pill needs to show the whole translated placeholder on one
   * line, its side padding included, and again whenever that width changes (a font loading, another language). The
   * host gives the pill this width, so the placeholder fits in every language.
   */
  onCollapsedPillWidthChange?: (collapsedPillWidthPx: number) => void;

  /**
   * Whether the AI is currently processing a response
   */
  isProcessing?: boolean;

  /**
   * Test ID for the component
   */
  testId?: string;

  /**
   * Additional CSS classes to apply to the component
   */
  className?: string;

  /**
   * Extra action controls rendered inside the input's bottom action row, left of the send button —
   * e.g. the agent model picker pill.
   */
  actionsSlot?: React.ReactNode;

  /**
   * Action controls pinned to the LEFT edge of the bottom action row (the `actionsSlot` cluster
   * stays right, next to the send button) — e.g. the agent "+" attachments menu.
   */
  actionsSlotStart?: React.ReactNode;

  /**
   * Host-owned content rendered above the editable text area, inside the input container —
   * attachment preview cards, validation error boxes, selection chips. Each injected block owns
   * its reveal/collapse animation.
   */
  topSlot?: React.ReactNode;

  /** Shown in place of the send button while there is nothing to send — e.g. the button that starts a voice chat. */
  sendReplacementWhenEmpty?: React.ReactNode;

  /** Painted behind the input, over its whole box — e.g. the voice glow of a running voice chat. */
  glowUnderlay?: React.ReactNode;

  /**
   * Hard send gate: blocks the send button AND Enter while true, but keeps the text editable
   * (unlike `disabled`/`isProcessing`). The host sets it e.g. while attachment uploads run.
   */
  sendDisabled?: boolean;

  /**
   * Uploaded media-library images that ride along in `onSendMessage`'s options object (`attachments`)
   * on the next send. Owned and cleared by the host; ConversationMessageInput only forwards them.
   */
  outgoingAttachmentImages?: ConversationAttachmentReference[];

  /**
   * Composer widgets that ride along on the next send — mirrors `outgoingAttachmentImages`. On send their fences
   * are appended to the typed text (`buildMessageTextWithComposerWidgets`) and they ride along in `onSendMessage`'s
   * options object (`composerWidgets`); a widget-only send (empty typed text) is allowed. Backspace on an empty
   * input removes the last part / widget via the widget context directly (`useConversationWidgetContext`).
   */
  outgoingComposerWidgets?: ConversationComposerWidget[];

  /** Called right after a successful send — the host clears the composer widgets it owns. */
  onComposerWidgetsSent?: () => void;

  /** Imperative handle for host-owned controls to put text into the input. */
  apiRef?: React.Ref<ConversationMessageInputHandle>;
}

export const ConversationMessageInput = (props: ConversationMessageInputProps) => {
  const {
    onSendMessage,
    placeholder = "Ask anything",
    disabled = false,
    isProcessing = false,
    testId = "chat-input",
    className,
    actionsSlot,
    actionsSlotStart,
    topSlot,
    sendReplacementWhenEmpty,
    glowUnderlay,
    sendDisabled = false,
    outgoingAttachmentImages,
    outgoingComposerWidgets,
    onComposerWidgetsSent,
    apiRef,
    compact = false,
    collapsed = false,
    onCollapsedPillWidthChange,
  } = props;

  const { removeComposerWidget, replaceComposerWidgetFields } = useConversationWidgetContext();

  const [inputValue, setInputValue] = useState("");
  const [isFocused, setIsFocused] = useState(false);
  const inputRef = useRef<HTMLSpanElement>(null);
  const isComposingRef = useRef(false);
  const translatedPlaceholder = useMemo(() => translateText(placeholder), [placeholder]);
  const collapsedPillSizerRef = useRef<HTMLSpanElement>(null);
  const measuresCollapsedPillWidth = compact && !!onCollapsedPillWidthChange;

  const isMessageTooLong = inputValue.length > MAX_MESSAGE_LENGTH;
  // Text OR at least one composer widget is enough to send (a fence-only message is allowed).
  const hasSendableContent = inputValue.trim() !== "" || (outgoingComposerWidgets?.length ?? 0) > 0;

  const focusInputAtEnd = () => {
    if (!inputRef.current) return;
    inputRef.current.focus();
    const sel = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(inputRef.current);
    range.collapse(false);
    sel?.removeAllRanges();
    sel?.addRange(range);
  };

  const setInputText = (text: string) => {
    setInputValue(text);
    if (inputRef.current) {
      inputRef.current.textContent = text;
      focusInputAtEnd();
    }
  };

  useImperativeHandle(apiRef, () => ({ insertTextIntoInput: setInputText }));

  const handleSendMessage = () => {
    const messageText = buildMessageTextWithComposerWidgets(inputValue, outgoingComposerWidgets ?? []);
    if (!messageText || disabled || isProcessing || isMessageTooLong || sendDisabled) return;

    onSendMessage?.(messageText, {
      attachments: outgoingAttachmentImages?.length ? outgoingAttachmentImages : undefined,
      composerWidgets: outgoingComposerWidgets?.length ? outgoingComposerWidgets : undefined,
    });
    onComposerWidgetsSent?.();
    setInputValue("");
    if (inputRef.current) {
      inputRef.current.textContent = "";
    }
  };

  // Backspace on an EMPTY input removes the last removable part of the last composer widget (declared via the
  // registry's `removeLastPartFromComposerWidgetFields`), or the whole widget when the entry omits it / nothing is
  // left. Mutates through the widget context directly — `outgoingComposerWidgets` only supplies the "which
  // widget is last" snapshot, mirroring the host-owned value ridden into the send.
  const handleBackspaceOnEmptyInput = () => {
    const widgets = outgoingComposerWidgets ?? [];
    const lastWidget = widgets[widgets.length - 1];
    if (!lastWidget) return;

    const entry = conversationWidgetRegistry.widgets.find((candidate) => candidate.name === lastWidget.widgetName);
    const removeLastPart = entry?.removeLastPartFromComposerWidgetFields;
    if (!removeLastPart) {
      removeComposerWidget(lastWidget.composerWidgetId);
      return;
    }
    const nextFields = removeLastPart(lastWidget.fields);
    if (nextFields === null) {
      removeComposerWidget(lastWidget.composerWidgetId);
    } else {
      replaceComposerWidgetFields(lastWidget.composerWidgetId, nextFields);
    }
  };

  const handleKeyPress = (e: KeyboardEvent) => {
    if (e.key === "Enter" && !e.shiftKey) {
      if (isComposingRef.current) return;
      e.preventDefault();
      handleSendMessage();
      return;
    }
    if (e.key === "Backspace" && inputValue === "" && (outgoingComposerWidgets?.length ?? 0) > 0) {
      e.preventDefault();
      handleBackspaceOnEmptyInput();
    }
  };

  const handleInput = () => {
    if (isComposingRef.current) return;
    if (!inputRef.current) return;
    setInputValue(inputRef.current.textContent || "");
  };

  const handleCompositionStart = () => {
    isComposingRef.current = true;
  };

  const handleCompositionEnd = () => {
    isComposingRef.current = false;
    handleInput();
  };

  const handleFocus = () => {
    setIsFocused(true);
  };

  const handleBlur = () => {
    setIsFocused(false);
  };

  const handleContainerMouseDown = (e: React.MouseEvent<HTMLDivElement>) => {
    const target = e.target as HTMLElement;
    // Let clicks on the editable span itself fall through — browser positions the caret at the click point.
    if (target === inputRef.current || inputRef.current?.contains(target)) return;
    // Let clicks on buttons (send, any future action buttons) fall through untouched.
    if (target.closest("button")) return;
    // Dead space click: redirect focus to end of input, but only when editing is allowed.
    if (disabled || isProcessing) return;
    e.preventDefault();
    focusInputAtEnd();
  };

  // Before the first paint, so the pill never shows at a width that cuts the placeholder off; again when the sizer's
  // text changes width.
  useLayoutEffect(() => {
    const collapsedPillSizer = collapsedPillSizerRef.current;
    if (!collapsedPillSizer || !onCollapsedPillWidthChange) return;
    const reportCollapsedPillWidth = () =>
      onCollapsedPillWidthChange(Math.ceil(collapsedPillSizer.getBoundingClientRect().width));
    reportCollapsedPillWidth();
    const resizeObserver = new ResizeObserver(reportCollapsedPillWidth);
    resizeObserver.observe(collapsedPillSizer);
    return () => resizeObserver.disconnect();
  }, [measuresCollapsedPillWidth, onCollapsedPillWidthChange]);

  const wasProcessingRef = useRef(isProcessing);
  useEffect(() => {
    if (wasProcessingRef.current && !isProcessing && !disabled) {
      inputRef.current?.focus();
    }
    wasProcessingRef.current = isProcessing;
  }, [isProcessing, disabled]);

  useEffect(() => {
    const handlePasteEvent = (e: ClipboardEvent) => {
      e.preventDefault();

      // Plain text (works across modern browsers)
      const text = e.clipboardData?.getData("text/plain");

      if (!text) {
        return;
      }

      // Insert text at caret position
      if (document.queryCommandSupported?.("insertText")) {
        document.execCommand("insertText", false, text);
      } else {
        // Fallback for newer/stricter environments
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        sel.deleteFromDocument();
        sel.getRangeAt(0).insertNode(document.createTextNode(text));
        sel.collapseToEnd();
      }
      // The fallback path mutates the DOM directly and fires no input event,
      // so sync state explicitly (harmless duplicate after execCommand).
      setInputValue(inputRef.current?.textContent || "");
    };
    const currentInput = inputRef.current;
    currentInput?.addEventListener("paste", handlePasteEvent);

    return () => {
      currentInput?.removeEventListener("paste", handlePasteEvent);
    };
  }, []);

  return (
    <div className={className}>
      {glowUnderlay}
      {/* Without its own base the glow takes the inherited --ai-base-color: the agent's own base colour on an agent surface, the
          category colour in an AI-extension category conversation (ConversationViewBody), the theme's AI colour
          elsewhere. */}
      <GlowFrame
        className={classNames(styles.aiGlow)}
        color={isMessageTooLong ? "var(--agents-danger-500)" : undefined}
        borderRadius={compact ? COMPACT_INPUT_RADIUS_PX : undefined}
        borderWidth={2}
        blurWidth={6}
      >
        <div
          className={classNames(styles.inputContainer, {
            [styles.compact]: compact,
            [styles.collapsed]: compact && collapsed,
          })}
          onMouseDown={handleContainerMouseDown}
        >
          {topSlot}
          <span
            ref={inputRef}
            contentEditable={!(disabled || isProcessing)}
            onInput={handleInput}
            onCompositionStart={handleCompositionStart}
            onCompositionEnd={handleCompositionEnd}
            onKeyDown={handleKeyPress}
            onFocus={handleFocus}
            onBlur={handleBlur}
            className={`${styles.editableInput} ${!inputValue && !isFocused ? styles.showPlaceholder : ""}`}
            data-placeholder={translatedPlaceholder}
            data-testid={`${testId}-textarea`}
          ></span>

          {/* Meta row above the actions: the character counter lives on its own line
              so it never crowds the send/attachment buttons on small screens. */}
          {inputValue.length >= CHARACTER_COUNTER_SHOWN_FROM_LENGTH && (
            <div className={styles.statusRow}>
              <Text
                role="caption"
                translate={false}
                color={isMessageTooLong ? "var(--agents-danger-500)" : "var(--agents-fg-lightest)"}
                className={styles.characterCounter}
                testId={`${testId}-character-counter`}
              >
                {`${inputValue.length.toLocaleString()} / ${MAX_MESSAGE_LENGTH.toLocaleString()}`}
              </Text>
            </div>
          )}

          <div className={styles.actions}>
            {actionsSlotStart && <div className={styles.actionsStart}>{actionsSlotStart}</div>}
            {/* <div className={styles.buttons}> */}
            {/* TODO: Implement emoji functionality */}
            {/* <Button
                type={ButtonTypes.muted}
                onClick={() => {
                }}
                testId={`${testId}-voice-button`}
                disabled={disabled || isProcessing}
                icon={IconNames.audioMicrophone}
                rounded
              /> */}
            {/* Over the cap: send is disabled and hovering it shows the popover with the
                reason. The wrapper carries the not-allowed cursor and routes hover past
                the disabled button to the popover (see .sendBlocked in the module scss). */}
            {actionsSlot}
            {sendReplacementWhenEmpty && !hasSendableContent ? (
              sendReplacementWhenEmpty
            ) : (
              <div className={classNames({ [styles.sendBlocked]: isMessageTooLong })}>
                <Tooltip
                  text={isMessageTooLong ? "Your message is over the %maxLength% character limit" : undefined}
                  replacements={{ maxLength: MAX_MESSAGE_LENGTH.toLocaleString() }}
                  placement="top"
                >
                  <Button
                    variant={
                      hasSendableContent && !isMessageTooLong && !sendDisabled ? "shimmer" : "muted"
                    }
                    onClick={handleSendMessage}
                    icon="arrowUp"
                    testId={`${testId}-send-button`}
                    disabled={!hasSendableContent || disabled || isProcessing || isMessageTooLong || sendDisabled}
                    size={hasSendableContent && !isMessageTooLong && !sendDisabled ? "medium" : undefined}
                    rounded
                  />
                </Tooltip>
              </div>
            )}
            {/* </div> */}
          </div>
        </div>
      </GlowFrame>
      {measuresCollapsedPillWidth && (
        <span ref={collapsedPillSizerRef} className={styles.collapsedPillSizer} aria-hidden="true">
          {translatedPlaceholder}
        </span>
      )}
    </div>
  );
};
