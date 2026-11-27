import { Button, GlowFrame, Text, Tooltip } from "../../provider/components";
import React, { useRef, useState, useEffect, useImperativeHandle, useLayoutEffect, useMemo, } from "react";
import styles from "./ConversationMessageInput.module.scss";
import { useAgentsTexts } from "../../provider/AgentsProvider";
import { classNames } from "../../provider/class-names";
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
export const ConversationMessageInput = (props) => {
    const { onSendMessage, placeholder = "Ask anything", disabled = false, isProcessing = false, testId = "chat-input", className, inputContainerClassName, actionsSlot, actionsSlotStart, topSlot, sendReplacementWhenEmpty, glowUnderlay, sendDisabled = false, outgoingAttachmentImages, hostComposerWidgets, outgoingComposerWidgets, onComposerWidgetsSent, apiRef, compact = false, compactStackedSlots = false, collapsed = false, onCollapsedPillWidthChange, } = props;
    const { removeComposerWidget, replaceComposerWidgetFields } = useConversationWidgetContext();
    const [inputValue, setInputValue] = useState("");
    const inputRef = useRef(null);
    const isComposingRef = useRef(false);
    // Through the texts of this subtree (an `AgentsProvider` environment's `texts`, else the app-wide ones).
    const texts = useAgentsTexts();
    const translatedPlaceholder = useMemo(() => texts.translate(placeholder), [texts, placeholder]);
    // The send button shows only an icon: its accessible name comes from the package's texts.
    const sendButtonAccessibleName = texts.translate("Send message");
    const collapsedPillSizerRef = useRef(null);
    const measuresCollapsedPillWidth = compact && !!onCollapsedPillWidthChange;
    const isCompactStacked = compact && compactStackedSlots;
    const isMessageTooLong = inputValue.length > MAX_MESSAGE_LENGTH;
    // Every composer widget the next send carries: the widget context's own, then the host's.
    const composerWidgetsToSend = [...(outgoingComposerWidgets ?? []), ...(hostComposerWidgets ?? [])];
    // Text OR at least one composer widget is enough to send (a fence-only message is allowed).
    const hasSendableContent = inputValue.trim() !== "" || composerWidgetsToSend.length > 0;
    const focusInputAtEnd = () => {
        if (!inputRef.current)
            return;
        inputRef.current.focus();
        const sel = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(inputRef.current);
        range.collapse(false);
        sel?.removeAllRanges();
        sel?.addRange(range);
    };
    const setInputText = (text) => {
        setInputValue(text);
        if (inputRef.current) {
            inputRef.current.textContent = text;
            focusInputAtEnd();
        }
    };
    useImperativeHandle(apiRef, () => ({ insertTextIntoInput: setInputText, focusInput: focusInputAtEnd }));
    const handleSendMessage = () => {
        const messageText = buildMessageTextWithComposerWidgets(inputValue, composerWidgetsToSend);
        if (!messageText || disabled || isProcessing || isMessageTooLong || sendDisabled)
            return;
        const accepted = onSendMessage?.(messageText, {
            attachments: outgoingAttachmentImages?.length ? outgoingAttachmentImages : undefined,
            composerWidgets: composerWidgetsToSend.length ? composerWidgetsToSend : undefined,
        });
        // A refused send (a closed conversation, a start still in flight) keeps the text and every composer widget.
        if (accepted === false)
            return;
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
        if (!lastWidget)
            return;
        const entry = conversationWidgetRegistry.widgets.find((candidate) => candidate.name === lastWidget.widgetName);
        const removeLastPart = entry?.removeLastPartFromComposerWidgetFields;
        if (!removeLastPart) {
            removeComposerWidget(lastWidget.composerWidgetId);
            return;
        }
        const nextFields = removeLastPart(lastWidget.fields);
        if (nextFields === null) {
            removeComposerWidget(lastWidget.composerWidgetId);
        }
        else {
            replaceComposerWidgetFields(lastWidget.composerWidgetId, nextFields);
        }
    };
    const handleKeyPress = (e) => {
        if (e.key === "Enter" && !e.shiftKey) {
            if (isComposingRef.current)
                return;
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
        if (isComposingRef.current)
            return;
        if (!inputRef.current)
            return;
        setInputValue(inputRef.current.textContent || "");
    };
    const handleCompositionStart = () => {
        isComposingRef.current = true;
    };
    const handleCompositionEnd = () => {
        isComposingRef.current = false;
        handleInput();
    };
    const handleContainerMouseDown = (e) => {
        const target = e.target;
        // Let clicks on the editable span itself fall through — browser positions the caret at the click point.
        if (target === inputRef.current || inputRef.current?.contains(target))
            return;
        // Let clicks on buttons (send, any future action buttons) fall through untouched.
        if (target.closest("button"))
            return;
        // Dead space click: redirect focus to end of input, but only when editing is allowed.
        if (disabled || isProcessing)
            return;
        e.preventDefault();
        focusInputAtEnd();
    };
    // Before the first paint, so the pill never shows at a width that cuts the placeholder off; again when the sizer's
    // text changes width.
    useLayoutEffect(() => {
        const collapsedPillSizer = collapsedPillSizerRef.current;
        if (!collapsedPillSizer || !onCollapsedPillWidthChange)
            return;
        const reportCollapsedPillWidth = () => onCollapsedPillWidthChange(Math.ceil(collapsedPillSizer.getBoundingClientRect().width));
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
        const handlePasteEvent = (e) => {
            e.preventDefault();
            // Plain text (works across modern browsers)
            const text = e.clipboardData?.getData("text/plain");
            if (!text) {
                return;
            }
            // Insert text at caret position
            if (document.queryCommandSupported?.("insertText")) {
                document.execCommand("insertText", false, text);
            }
            else {
                // Fallback for newer/stricter environments
                const sel = window.getSelection();
                if (!sel || sel.rangeCount === 0)
                    return;
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
    return (<div className={className}>
      {glowUnderlay}
      {/* Without its own base the glow takes the inherited --ai-base-color: the agent's own base colour on an agent surface, the
            category colour in an AI-extension category conversation (ConversationViewBody), the theme's AI colour
            elsewhere. */}
      <GlowFrame className={classNames(styles.aiGlow)} color={isMessageTooLong ? "var(--agents-danger-500)" : undefined} borderRadius={compact ? COMPACT_INPUT_RADIUS_PX : undefined} borderWidth={2} blurWidth={6}>
        <div className={classNames(styles.inputContainer, {
            [styles.compact]: compact,
            [styles.compactStacked]: isCompactStacked,
            [styles.compactWithActionsStart]: isCompactStacked && !!actionsSlotStart,
            [styles.collapsed]: compact && collapsed,
        }, inputContainerClassName)} onMouseDown={handleContainerMouseDown}>
          {/* Stacked compact form: one flex row, the top slot wraps onto a row of its own above it, and the start
            cluster stands left of the text instead of in the action row. */}
          {isCompactStacked && !!topSlot && <div className={styles.compactTopSlot}>{topSlot}</div>}
          {!isCompactStacked && topSlot}
          {isCompactStacked && !!actionsSlotStart && (<div className={styles.compactActionsStart}>{actionsSlotStart}</div>)}
          <span ref={inputRef} contentEditable={!(disabled || isProcessing)} onInput={handleInput} onCompositionStart={handleCompositionStart} onCompositionEnd={handleCompositionEnd} onKeyDown={handleKeyPress} 
    // The placeholder shows while the input is empty, focused or not: the chat focuses the input after every
    // turn, and a focused empty input without it read as a field without purpose.
    className={`${styles.editableInput} ${!inputValue ? styles.showPlaceholder : ""}`} data-placeholder={translatedPlaceholder} data-testid={`${testId}-textarea`}></span>

          {/* Meta row above the actions: the character counter lives on its own line
            so it never crowds the send/attachment buttons on small screens. */}
          {inputValue.length >= CHARACTER_COUNTER_SHOWN_FROM_LENGTH && (<div className={styles.statusRow}>
              <Text role="caption" translate={false} color={isMessageTooLong ? "var(--agents-danger-500)" : "var(--agents-fg-lightest)"} className={styles.characterCounter} testId={`${testId}-character-counter`}>
                {`${inputValue.length.toLocaleString()} / ${MAX_MESSAGE_LENGTH.toLocaleString()}`}
              </Text>
            </div>)}

          <div className={styles.actions}>
            {!isCompactStacked && !!actionsSlotStart && <div className={styles.actionsStart}>{actionsSlotStart}</div>}
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
            {sendReplacementWhenEmpty && !hasSendableContent ? (sendReplacementWhenEmpty) : (<div className={classNames(styles.ownAction, { [styles.sendBlocked]: isMessageTooLong })}>
                <Tooltip text={isMessageTooLong ? "Your message is over the %maxLength% character limit" : undefined} replacements={{ maxLength: MAX_MESSAGE_LENGTH.toLocaleString() }} placement="top">
                  <Button variant={hasSendableContent && !isMessageTooLong && !sendDisabled ? "shimmer" : "muted"} onClick={handleSendMessage} icon="arrowUp" testId={`${testId}-send-button`} ariaLabel={sendButtonAccessibleName} disabled={!hasSendableContent || disabled || isProcessing || isMessageTooLong || sendDisabled} size={hasSendableContent && !isMessageTooLong && !sendDisabled ? "medium" : undefined} rounded/>
                </Tooltip>
              </div>)}
            {/* </div> */}
          </div>
        </div>
      </GlowFrame>
      {measuresCollapsedPillWidth && (<span ref={collapsedPillSizerRef} className={styles.collapsedPillSizer} aria-hidden="true">
          {translatedPlaceholder}
        </span>)}
    </div>);
};
