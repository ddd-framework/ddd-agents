import { Text } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import styles from "./ConversationViewBody.module.scss";
import { ConversationMessageInput, } from "../ConversationMessageInput/ConversationMessageInput";
import { ConversationMessageInputComposerWidgets } from "../ConversationMessageInput/ConversationMessageInputComposerWidgets/ConversationMessageInputComposerWidgets";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetContext";
import { ConversationErrorType } from "../store/conversation-error-type";
import { NoConversationLoading, ConversationLoading } from "../ConversationLoadingStates";
import { ConversationMessageList } from "../ConversationMessageList/ConversationMessageList";
import { WidgetWindowRootContext } from "../../renderer/widget-windowing";
import { ConversationIntroWidget } from "../ConversationIntroWidget/ConversationIntroWidget";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";
import { ConversationScrollToLatestMessageButton } from "../ConversationScrollToLatestMessageButton/ConversationScrollToLatestMessageButton";
import { useIsMessagesBottomOutOfView } from "./hooks/useIsMessagesBottomOutOfView";
import { usePinQuestionOnSend } from "./hooks/usePinQuestionOnSend";
export const ConversationViewBody = (props) => {
    const { currentCategory, aiPromptExtension, suggestions, messages, aiThinking, error, rateLimitRetryAfterSeconds, onRetryFailedRun, isDebugMode, categoriesLoaded, startingNewConversation, conversationId, showMessagesWithoutConversation, autoScroll = true, thinkingIndicator, background, backgroundPaintedByHost, progressStepperSlot, fileDropHintSlot, scrollRef, scrollToBottomNonce, followBottomRef, scrollJumpActiveRef, hideAssistantMeta, userMessageTheme, isCompact, confineTopBlurBandToChatPane, hasMoreOlder, loadingOlder, onLoadOlder, inputActionsSlot, inputActionsSlotStart, inputTopSlot, inputSendDisabled, inputReplacementSlot, inputBandSlot, inputPlaceholder, inputSendReplacementWhenEmpty, inputGlowUnderlay, contentOverlaySlot, isContentOverlayShown = false, outgoingAttachmentImages, hostComposerWidgets, conversationMessageInputApiRef, allowSendWhileAiThinking, enablePinQuestionAndRevealAnswer = false, isQuestionPinSuspended = false, taskInstructionMessageId, renderAfterMessage, renderAssistantAvatar, isConversationClosed, onSuggestionClick, onSendMessage, } = props;
    // Composer widgets ride the widget context itself (like the deleted selection chip did), so every
    // ConversationView host gets the strip, the outgoing-on-send value and the clear-on-send wiring for free.
    const { composerWidgets, clearComposerWidgets } = useConversationWidgetContext();
    const messagesBottom = useRef(null);
    const [scrollContainer, setScrollContainer] = useState(null);
    const [messagesList, setMessagesList] = useState(null);
    // Bottom-follow flag. Uses the host-supplied ref when given (so a host-driven jump can suspend follow, see
    // `followBottomRef` in props), otherwise a local one — identical behaviour when no host overrides it.
    const internalFollowBottom = useRef(true);
    const followBottom = followBottomRef ?? internalFollowBottom;
    // Rendered USER-row count at the last positioning pass — an increase within the same conversation means the user
    // just sent (the pin trigger). Counted from the DOM (not the messages array) so hidden USER messages (/toolresult
    // reports, SIGNAL/ERROR) never miscount and it always matches the row pinLatestQuestionToTop targets.
    const prevUserMessageCount = useRef(0);
    // Last conversationId the positioning effect saw, to tell a real switch / new-chat (re-anchor to bottom) apart from
    // the cold-start id landing mid-turn (undefined → a freshly-created id while pinned → keep the pin).
    const prevConversationId = useRef(conversationId);
    // Scroll-up pagination (opt-in via onLoadOlder). Capturing the scrollHeight when an older-load starts lets the
    // prepend layout-effect restore the viewport so it doesn't jump when the older page merges in.
    const prependAnchorScrollHeight = useRef(null);
    // Combined ref callback for the scrollable content wrapper: drives the
    // internal state ref (the auto-scroll / follow-bottom effects depend on it)
    // AND forwards to the caller-supplied `scrollRef` when present. Stable identity
    // so the ref isn't detached/reattached on every render.
    const handleContentWrapperRef = useCallback((el) => {
        setScrollContainer(el);
        if (typeof scrollRef === "function")
            scrollRef(el);
        else if (scrollRef)
            scrollRef.current = el;
    }, [scrollRef]);
    // The named assistant's theme and background come from the app; the agent chat shows none.
    const { readAssistantThemeClassName, AssistantBackground } = useAgentsEnvironment();
    const colorPalette = useMemo(() => currentCategory?.alias
        ? (readAssistantThemeClassName?.(currentCategory.alias) ?? styles.genericChat)
        : styles.genericChat, [currentCategory, readAssistantThemeClassName]);
    const isMessageListVisible = (!!messages && (!!conversationId || !!showMessagesWithoutConversation)) ||
        startingNewConversation ||
        (!!error && error !== ConversationErrorType.CONVERSATION_NOT_FOUND);
    const isConversationIntroVisible = useMemo(() => aiPromptExtension !== undefined &&
        !conversationId &&
        !startingNewConversation &&
        !messages &&
        !(!!error && error !== ConversationErrorType.CONVERSATION_NOT_FOUND), [aiPromptExtension, conversationId, startingNewConversation, messages, error]);
    // The list DOM node. Prefer the state ref (it drives the ResizeObserver effect); fall back to the bottom marker's
    // parent, which is always the .chatMessages list — needed in the layout effect on the render where the list first
    // mounts (setMessagesList hasn't flushed to state yet, but messagesBottom's ref is already committed).
    const getMessagesListEl = useCallback(() => messagesList ?? messagesBottom.current?.parentElement ?? null, [messagesList]);
    const { pinLatestQuestionToTop, reserveRoomBelowPinnedQuestion, isQuestionPinnedThisTurn, endPinnedTurn, userMessageRowCount, } = usePinQuestionOnSend({
        enabled: enablePinQuestionAndRevealAnswer,
        scrollContainer,
        getMessagesListEl,
        messagesBottomRef: messagesBottom,
        followBottomRef: followBottom,
    });
    // First positioning is instant and pre-paint (layout effect + behavior "auto"):
    // opening a conversation lands directly at the bottom with no visible scroll
    // animation. Subsequent message updates scroll smoothly.
    const hasPositionedOnce = useRef(false);
    useLayoutEffect(() => {
        // Older-page prepend (scroll-up pagination): restore the viewport so the freshly-prepended messages don't shift
        // it, and do NOT auto-scroll to the bottom. The anchor is only set when an older-load started, so a conversation
        // switch / live tick never enters this branch.
        if (prependAnchorScrollHeight.current !== null) {
            if (scrollContainer) {
                const delta = scrollContainer.scrollHeight - prependAnchorScrollHeight.current;
                if (delta > 0)
                    scrollContainer.scrollTop += delta;
            }
            prependAnchorScrollHeight.current = null;
            return;
        }
        if (!autoScroll) {
            hasPositionedOnce.current = false;
            return;
        }
        // Addon off (default): the classic bottom-follow — re-anchor to the bottom on every message update, exactly the
        // pre-AW-107 behavior (including the old quirk that it scrolls even before the list mounts, no messagesBottom guard).
        if (!enablePinQuestionAndRevealAnswer) {
            followBottom.current = true;
            messagesBottom.current?.scrollIntoView({
                behavior: hasPositionedOnce.current ? "smooth" : "auto",
                block: "end",
                inline: "nearest",
            });
            hasPositionedOnce.current = true;
            return;
        }
        // A real conversation switch / new-chat / first history load re-anchors to the bottom. The one exception is the
        // cold-start id landing (undefined → a freshly-created id) MID-TURN: the question was already pinned on send, so
        // keep it pinned instead of yanking back to the bottom.
        const conversationChanged = prevConversationId.current !== conversationId;
        const isColdStartIdLanding = prevConversationId.current === undefined && isQuestionPinnedThisTurn();
        prevConversationId.current = conversationId;
        if (conversationChanged && !isColdStartIdLanding) {
            endPinnedTurn();
            followBottom.current = true;
            hasPositionedOnce.current = false;
        }
        const userCount = userMessageRowCount();
        // First positioning for this conversation. If the list isn't mounted yet (still loading / intro screen), wait for
        // it. A fresh cold-start send (startingNewConversation, nothing positioned yet) pins the first question to the top;
        // opening an existing conversation lands at the bottom, as today.
        if (!hasPositionedOnce.current) {
            if (!messagesBottom.current)
                return; // list not in the DOM yet — nothing to position
            hasPositionedOnce.current = true;
            prevUserMessageCount.current = userCount;
            if (startingNewConversation && userCount > 0) {
                pinLatestQuestionToTop("auto");
            }
            else {
                followBottom.current = true;
                messagesBottom.current.scrollIntoView({ behavior: "auto", block: "end", inline: "nearest" });
            }
            return;
        }
        // Same conversation, already positioned: a new USER row means the user just sent → pin the new question to the top.
        if (userCount > prevUserMessageCount.current && !isQuestionPinSuspended) {
            prevUserMessageCount.current = userCount;
            pinLatestQuestionToTop("smooth");
            return;
        }
        prevUserMessageCount.current = userCount;
        // A later update within the same turn (the reply arriving / growing): keep the question pinned and just re-reserve
        // room — never scroll. Outside a pinned turn (e.g. a background refresh) keep today's bottom-follow.
        if (isQuestionPinnedThisTurn()) {
            reserveRoomBelowPinnedQuestion();
        }
        else if (followBottom.current) {
            messagesBottom.current?.scrollIntoView({ behavior: "smooth", block: "end", inline: "nearest" });
        }
    }, [
        messages,
        autoScroll,
        scrollContainer,
        conversationId,
        startingNewConversation,
        enablePinQuestionAndRevealAnswer,
        isQuestionPinSuspended,
        userMessageRowCount,
        reserveRoomBelowPinnedQuestion,
        pinLatestQuestionToTop,
        isQuestionPinnedThisTurn,
        endPinnedTurn,
        followBottom,
    ]);
    // Capture the scroll height the instant an older-load starts — consumed by the prepend layout-effect above to keep
    // the viewport anchored once the older page merges in.
    useEffect(() => {
        if (loadingOlder && scrollContainer) {
            prependAnchorScrollHeight.current = scrollContainer.scrollHeight;
        }
    }, [loadingOlder, scrollContainer]);
    // Scroll-up lazy-load: prefetch the next older page when the user scrolls within ~300px of the top. onLoadOlder is
    // self-guarded (in-flight + hasMoreOlder) by the caller, so repeated scroll ticks near the top don't double-fetch.
    // No-op unless the caller opts in via onLoadOlder.
    useEffect(() => {
        if (!onLoadOlder || !scrollContainer || !hasMoreOlder)
            return undefined;
        const onScrollNearTop = () => {
            if (scrollContainer.scrollTop <= 300)
                onLoadOlder();
        };
        scrollContainer.addEventListener("scroll", onScrollNearTop, { passive: true });
        return () => scrollContainer.removeEventListener("scroll", onScrollNearTop);
    }, [onLoadOlder, scrollContainer, hasMoreOlder]);
    useEffect(() => {
        if (!autoScroll)
            return;
        const container = scrollContainer;
        const list = messagesList;
        if (!container || !list)
            return;
        const NEAR_BOTTOM_PX = 80;
        const isNearBottom = () => container.scrollHeight - container.scrollTop - container.clientHeight <= NEAR_BOTTOM_PX;
        let animRaf = null;
        let isProgrammaticScroll = false;
        const cancelAnim = () => {
            if (animRaf !== null) {
                cancelAnimationFrame(animRaf);
                animRaf = null;
            }
        };
        // Non-interruptible smooth scroll: if already running, the existing
        // animation just re-reads the target each frame and chases it — no
        // restart, no blink. Only user interaction or unmount cancels it.
        const startSmoothToBottom = (durationMs) => {
            if (animRaf !== null)
                return; // in-flight; target re-read each frame
            const start = container.scrollTop;
            const initialTarget = container.scrollHeight - container.clientHeight;
            if (initialTarget - start <= 0)
                return;
            const t0 = performance.now();
            const easeOut = (t) => 1 - Math.pow(1 - t, 3);
            const tick = (now) => {
                const t = Math.min(1, (now - t0) / durationMs);
                const target = container.scrollHeight - container.clientHeight;
                isProgrammaticScroll = true;
                container.scrollTop = start + (target - start) * easeOut(t);
                isProgrammaticScroll = false;
                if (t < 1) {
                    animRaf = requestAnimationFrame(tick);
                }
                else {
                    animRaf = null;
                }
            };
            animRaf = requestAnimationFrame(tick);
        };
        const disableFollow = () => {
            followBottom.current = false;
            // A pinned turn (AW-107) keeps its pin + reserved room across a manual scroll — we deliberately do NOT clear them
            // here. Dropping the padding on scroll shrank the scroll height and made the viewport jump; and the empty space
            // below a short reply is intentional, so the question holds its place at the top for the whole turn.
            cancelAnim();
        };
        const onScroll = () => {
            if (isProgrammaticScroll)
                return;
            // A host-driven jump is in flight: don't re-enable follow just because the viewport is still/again near the
            // bottom — that clobbers the jump and lets the resize-follow yank the view back down.
            if (scrollJumpActiveRef?.current)
                return;
            if (isNearBottom())
                followBottom.current = true;
        };
        container.addEventListener("wheel", disableFollow, { passive: true });
        container.addEventListener("touchmove", disableFollow, { passive: true });
        container.addEventListener("keydown", disableFollow);
        container.addEventListener("scroll", onScroll, { passive: true });
        const observer = new ResizeObserver(() => {
            // A host-driven jump is in flight (search-result jump): the messages settle their heights as they render, which
            // fires this observer — do NOT auto-scroll to the bottom, or the jump gets yanked back down.
            if (scrollJumpActiveRef?.current)
                return;
            // During a pinned turn the reply grows below the fold: re-reserve room (it shrinks to 0 as the reply fills the
            // viewport) — never scroll, so the question stays put at the top. Inert when the addon is off (pin never set).
            if (isQuestionPinnedThisTurn()) {
                reserveRoomBelowPinnedQuestion();
                return;
            }
            if (!followBottom.current)
                return;
            startSmoothToBottom(180);
        });
        observer.observe(list);
        // Also react to viewport/pane height changes (window resize, drawer open/close): the list observer won't fire when
        // only the container's height changes, but a pinned turn's reserved room depends on container.clientHeight. Only
        // needed for the addon — leaving it on for the default chat would newly trigger scroll-to-bottom on drawer toggle.
        if (enablePinQuestionAndRevealAnswer)
            observer.observe(container);
        return () => {
            container.removeEventListener("wheel", disableFollow);
            container.removeEventListener("touchmove", disableFollow);
            container.removeEventListener("keydown", disableFollow);
            container.removeEventListener("scroll", onScroll);
            observer.disconnect();
            cancelAnim();
        };
    }, [
        isMessageListVisible,
        scrollContainer,
        messagesList,
        autoScroll,
        enablePinQuestionAndRevealAnswer,
        isQuestionPinnedThisTurn,
        reserveRoomBelowPinnedQuestion,
        followBottom,
        scrollJumpActiveRef,
    ]);
    // Only observe the sentinel when the addon is on — a null container makes the hook return false + mount no observer.
    const isMessagesBottomOutOfView = useIsMessagesBottomOutOfView(enablePinQuestionAndRevealAnswer ? scrollContainer : null, messagesList, messagesBottom);
    // Jump back to the newest message. Ends a pinned turn: the user is explicitly leaving the pinned
    // question, so bottom-follow takes over again (the ResizeObserver's pinned branch would otherwise
    // keep suppressing it, ConversationViewBody.tsx:396). Dropping the reserved padding here cannot jump the
    // viewport: the button only shows when the viewport is ≥300px ABOVE the sentinel, and the padding
    // lives entirely BELOW it — shrinking scrollHeight below the viewport doesn't move scrollTop.
    const scrollToLatestMessage = useCallback(() => {
        endPinnedTurn();
        followBottom.current = true;
        messagesBottom.current?.scrollIntoView({ behavior: "smooth", block: "end", inline: "nearest" });
    }, [endPinnedTurn, followBottom]);
    // Caller-driven scroll-to-bottom (scrollToBottomNonce bumped per widget/CTA-click signal). Goes through
    // scrollToLatestMessage so it clears any pinned turn and re-enables bottom-follow — a raw scrollTo would be
    // suppressed while a turn is pinned. Ignores the initial 0/undefined so it never scrolls on mount.
    useEffect(() => {
        if (!scrollToBottomNonce)
            return;
        scrollToLatestMessage();
    }, [scrollToBottomNonce, scrollToLatestMessage]);
    const messageColumn = (<div className={styles.contentWrapper} 
    // A stable hook for a host's own stylesheet (the default chat drops the top blur band, it has no top bar).
    data-agents-chat-scroll="" data-testid="chat-messages-scroll" ref={handleContentWrapperRef} aria-hidden={isContentOverlayShown || undefined} inert={isContentOverlayShown || undefined}>
      {!conversationId && !categoriesLoaded && !aiPromptExtension ? (<NoConversationLoading />) : null}

      {!!conversationId && !messages ? (<ConversationLoading />) : null}

      {isConversationIntroVisible ? (<ConversationIntroWidget aiPromptExtension={aiPromptExtension} aiPromptExtensionIcon={aiPromptExtension.iconClass} suggestions={suggestions} onSuggestionClick={onSuggestionClick}/>) : null}

      {!!loadingOlder ? (<span className={styles.olderLoading}>
          <Text>{"Loading older messages…"}</Text>
        </span>) : null}

      {isMessageListVisible ? (<>
          {/* The scroll container (this contentWrapper) is the IntersectionObserver root for widget viewport-windowing. */}
          <WidgetWindowRootContext.Provider value={scrollContainer}>
            <ConversationMessageList className={enablePinQuestionAndRevealAnswer && (conversationId || startingNewConversation || aiThinking || error)
                ? styles.topAlignedMessages
                : undefined} messages={messages} currentCategory={currentCategory} aiPromptExtension={aiPromptExtension} isDebugMode={isDebugMode} aiThinking={aiThinking} thinkingIndicator={thinkingIndicator} hideAssistantMeta={hideAssistantMeta} userMessageTheme={userMessageTheme} error={error} rateLimitRetryAfterSeconds={rateLimitRetryAfterSeconds} onRetryFailedRun={onRetryFailedRun} messagesBottomRef={messagesBottom} messagesListRef={setMessagesList} enableFreshMessageBlockReveal={enablePinQuestionAndRevealAnswer} taskInstructionMessageId={taskInstructionMessageId} renderAfterMessage={renderAfterMessage} renderAssistantAvatar={renderAssistantAvatar} isConversationClosed={isConversationClosed}/>
          </WidgetWindowRootContext.Provider>
        </>) : null}
    </div>);
    return (<div className={classNames(styles.chat, background || backgroundPaintedByHost ? styles.customBg : colorPalette, backgroundPaintedByHost ? styles.backgroundPaintedByHost : undefined, isCompact ? styles.chatCompact : undefined, confineTopBlurBandToChatPane ? styles.confineTopBlurBandToChatPane : undefined)}>
      {background ? (<div className={styles.backgroundLayer}>{background}</div>) : backgroundPaintedByHost ? null : (AssistantBackground ? (<AssistantBackground category={currentCategory} categoryAlias={currentCategory?.alias ?? undefined}/>) : null)}

      {/* Positions itself against this .chat root (see the prop's doc) — no wrapper, no class from here. */}
      {progressStepperSlot}
      {fileDropHintSlot}

      {contentOverlaySlot !== undefined ? (<div className={styles.contentLayers} data-overlay-shown={isContentOverlayShown || undefined}>
          {messageColumn}
          <div className={styles.contentOverlay} aria-hidden={!isContentOverlayShown || undefined} inert={!isContentOverlayShown || undefined}>
            {contentOverlaySlot}
          </div>
        </div>) : (messageColumn)}
      {enablePinQuestionAndRevealAnswer && (<div className={styles.scrollToLatestAnchor}>
          <ConversationScrollToLatestMessageButton visible={isMessagesBottomOutOfView} onClick={scrollToLatestMessage}/>
        </div>)}
      {/* With allowSendWhileAiThinking the input stays open during a turn, EXCEPT while the cold-start
            startWithMessage POST is in flight: no conversation id exists yet, so the host's send handler would drop a
            second message silently — keep the brief lock for exactly that window. */}
      {inputReplacementSlot ? (<div className={styles.inputReplacement}>{inputReplacementSlot}</div>) : (<>
          {inputBandSlot}
          <ConversationMessageInput className={classNames(styles.input, currentCategory?.alias ? styles.inputInCategory : undefined)} isProcessing={allowSendWhileAiThinking ? !!startingNewConversation : aiThinking} onSendMessage={onSendMessage} actionsSlot={inputActionsSlot} actionsSlotStart={inputActionsSlotStart} 
        // The composer-widgets strip is one topSlot block like any other — above the host's own blocks
        // (attachment previews sit closest to the text). It reads the widget context itself, so every
        // ConversationView host gets it for free.
        topSlot={<>
                <ConversationMessageInputComposerWidgets testId="chat-input"/>
                {inputTopSlot}
              </>} sendDisabled={inputSendDisabled} outgoingAttachmentImages={outgoingAttachmentImages} hostComposerWidgets={hostComposerWidgets} outgoingComposerWidgets={composerWidgets} onComposerWidgetsSent={clearComposerWidgets} apiRef={conversationMessageInputApiRef} placeholder={inputPlaceholder} sendReplacementWhenEmpty={inputSendReplacementWhenEmpty} glowUnderlay={inputGlowUnderlay}/>
        </>)}
    </div>);
};
