import { Text } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import { useMemo, useRef, useState } from "react";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { appendAnotherAgentSpaceToOwnerText } from "../../provider/environment";
import { readWidgetAgentSpaceKeyAttribute } from "../../renderer/_agent-space/widget-agent-space-key-attribute";
import { useResolvedConversationWidgetAgentSpaceKey } from "../../renderer/_agent-space/use-resolved-conversation-widget-agent-space-key";
import { ConversationWidgetAgentSpaceFrame } from "../../renderer/_agent-space/ConversationWidgetAgentSpaceFrame";
import { DonePill } from "../_shared/DonePill";
import { useStackWhenWrapped } from "../_shared/use-stack-when-wrapped";
import { parseQuickRepliesPayload, truncateQuickReplyLabel } from "./shared/quick-replies-widget-payload";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import styles from "./QuickRepliesConversationWidget.module.scss";

type QuickRepliesConversationWidgetProps = PayloadConversationWidgetProps;

const testId = "quick-replies-widget";

/** A blank marker inside an option's text: an ellipsis (either glyph), a bracketed placeholder (`[...]`/`[…]`), or a
 *  run of underscores — the shapes a template-with-a-blank option takes. Not global: `isQuickReplyPlaceholderOption`
 *  only needs yes/no; `stripQuickReplyPlaceholderMarker` makes its own global copy to remove every occurrence. */
const PLACEHOLDER_MARKER_PATTERN = /\[\s*(?:\.\.\.|…)\s*]|_{3,}|\.\.\.|…/;

/**
 * A "placeholder option" is a QuickReplies option that is a TEMPLATE with a blank rather than a complete answer the
 * owner could send as-is (the backend has a hard rule against this — this predicate is the widget's
 * own backstop, since a malformed agent turn can still emit one). Three tells, any one is enough: an ellipsis
 * (`…` or `...`), a bracketed/underscore blank (`[...]`, `[…]`, `___`),
 * or the option simply ending in a colon (introduces an answer it never supplies). Clicking such an option must
 * never send its literal text — see `handleClick` below.
 */
export const isQuickReplyPlaceholderOption = (option: string): boolean => {
  const trimmed = option.trim();
  return PLACEHOLDER_MARKER_PATTERN.test(trimmed) || trimmed.endsWith(":");
};

/**
 * The text a placeholder-option click puts into the composer: every blank marker removed, trailing whitespace
 * trimmed, then ONE trailing space so the owner's cursor lands ready to type (e.g. `"Yes, we deliver to these
 * areas: …"` → `"Yes, we deliver to these areas: "`). A colon-only ending (no separate marker) is left as-is before
 * the trailing space is added — the colon is the prompt, not the blank.
 */
export const stripQuickReplyPlaceholderMarker = (option: string): string => {
  const stripped = option.trim().replace(new RegExp(PLACEHOLDER_MARKER_PATTERN.source, "g"), "").trimEnd();
  return `${stripped} `;
};

/**
 * The closed-question answer pills (a backend hard rule): two to five short options, each a
 * complete answer the owner could also type. A click posts that option's text as the owner's message — single pick,
 * never a multi-select (that is `MultiChoiceConversationWidget`).
 *
 * A placeholder option (`isQuickReplyPlaceholderOption`) is the one exception: it never sends. The click instead
 * puts the stripped text into the composer and focuses it (`prefillComposer`), so the owner completes the sentence
 * themselves instead of the literal blank going out as their message.
 *
 * Locks only once the host CONFIRMS the send was accepted (`sendMessageToChat`'s `void | Promise<boolean>`
 * contract) — a rejected send (a closed conversation, an in-flight start) leaves the pills clickable again, and a
 * second click while one is already in flight is ignored. Once locked, the pills give way to one pill naming the
 * chosen answer (`DonePill`).
 *
 * Bound to its own host message (`messageId`): once a NEWER owner message exists in the conversation (the owner
 * typed past it, or answered a later question), the widget goes inert — `interactive` alone does not catch this,
 * since it stays true for the rest of the live session, not just until the next owner message.
 */
export const QuickRepliesConversationWidget = (props: QuickRepliesConversationWidgetProps) => {
  const { payload, interactive = false, messageId } = props;
  const { sendMessageToChat, prefillComposer, messages } = useConversationWidgetContext();
  const isEmailRender = useIsEmailWidgetRender();
  const { anotherAgentSpaceKey, isAnotherAgentSpace } = useResolvedConversationWidgetAgentSpaceKey(
    readWidgetAgentSpaceKeyAttribute(props),
  );

  const data = useMemo(() => parseQuickRepliesPayload(payload), [payload]);
  const rowRef = useStackWhenWrapped<HTMLDivElement>(data?.options);

  const [chosenOption, setChosenOption] = useState<string | null>(null);
  const [locked, setLocked] = useState(false);
  const [pending, setPending] = useState(false);
  const pendingRef = useRef(false);

  // A newer owner (USER) message exists past this widget's host message — the conversation moved on, so a stale pick
  // here can no longer answer it. `messages` is ordered oldest → newest (the transcript order), so everything past
  // the host message's position is a later turn.
  const isStale = useMemo(() => {
    if (!messages || messageId === undefined) return false;
    const hostIndex = messages.findIndex((message) => String(message.id) === String(messageId));
    if (hostIndex === -1) return false;
    return messages.slice(hostIndex + 1).some((message) => message.role === "USER");
  }, [messages, messageId]);

  const isUnreadable = data === null;
  useReportWidgetRenderStatus(
    isUnreadable ? "failed" : "mounted",
    isUnreadable ? "INVALID_PROP" : undefined,
    isUnreadable
      ? "The QuickReplies options must be 2 to 5 distinct non-empty strings; the widget was dropped."
      : undefined,
  );

  if (!data) return null;

  const inert = !interactive || isStale;
  const disabled = inert || locked || pending;

  const handleClick = async (option: string) => {
    if (disabled || pendingRef.current) return;
    if (isQuickReplyPlaceholderOption(option)) {
      // A template-with-a-blank option — sending it as-is would post the literal blank as the owner's message.
      // Put the completed-so-far text into the composer instead and let the owner finish it.
      prefillComposer(stripQuickReplyPlaceholderMarker(option));
      return;
    }
    pendingRef.current = true;
    setPending(true);
    setChosenOption(option);
    try {
      const outgoing =
        isAnotherAgentSpace && anotherAgentSpaceKey !== undefined
          ? appendAnotherAgentSpaceToOwnerText(option, anotherAgentSpaceKey)
          : option;
      const result = await sendMessageToChat(outgoing);
      if (result === false) {
        // Not accepted — leave the widget usable, nothing was recorded as chosen.
        setChosenOption(null);
      } else {
        // `true` or `void` (a host that doesn't report) both count as accepted.
        setLocked(true);
      }
    } catch {
      setChosenOption(null);
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  };

  return (
    <ConversationWidgetAgentSpaceFrame anotherAgentSpaceKey={isAnotherAgentSpace ? anotherAgentSpaceKey : undefined}>
      {/* The live region is there before the pills give way to the pill, so a screen reader announces it. */}
      <div aria-live="polite" data-testid={testId}>
        {locked && chosenOption !== null ? (
          <DonePill translate={false} testId={`${testId}-done`}>
            {truncateQuickReplyLabel(chosenOption)}
          </DonePill>
        ) : (
          <div ref={rowRef} className={styles.wrap} role="group" data-testid={`${testId}-options`}>
            {data.options.map((option) => {
              if (isEmailRender)
                return (
                  <Text key={option} role="help" translate={false} testId={`${testId}-option-${option}`}>
                    {option}
                  </Text>
                );
              const isChosen = chosenOption === option;
              return (
                <button
                  key={option}
                  type="button"
                  className={classNames(styles.pill, isChosen && styles.pillChosen)}
                  disabled={disabled}
                  onClick={() => handleClick(option)}
                  data-testid={`${testId}-option-${option}`}
                >
                  <Text role="help" translate={false} className={styles.pillLabel}>
                    {truncateQuickReplyLabel(option)}
                  </Text>
                </button>
              );
            })}
          </div>
        )}
      </div>
    </ConversationWidgetAgentSpaceFrame>
  );
};
