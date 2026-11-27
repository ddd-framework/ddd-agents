import { useEffect, useMemo, useRef, useState } from "react";
import { useAgentsTexts } from "../../provider/AgentsProvider";
import { classNames } from "../../provider/class-names";
import { Button, Markdown, Text } from "../../provider/components";
import type { AgentsMarkdownComponents } from "../../provider/components/types";
import { textKey } from "../../provider/text-key";
import { ModelText } from "../../kit/ModelText/ModelText";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { WidgetStatusChip, WidgetSurface } from "../_shared/modern";
import { isSentMailDraftPreviewOf, parseMailDraftPreviewPayload } from "./mail-draft-preview-payload";
import styles from "./MailDraftPreviewConversationWidget.module.scss";

const TEST_ID = "mail-draft-preview-widget";

/**
 * An image in the mail body (model text) shows as its alt text: a remote image would load in the owner's browser (a
 * tracking pixel an injected instruction could plant), and the sent mail carries none either.
 */
const ImageAsAltText = (props: Record<string, unknown>) => <span>{typeof props.alt === "string" ? props.alt : ""}</span>;

const MAIL_BODY_MARKDOWN_COMPONENTS: AgentsMarkdownComponents = { img: ImageAsAltText };

/** The height the body folds to; a body only slightly taller is shown whole. */
const BODY_FOLD_HEIGHT_PX = 240;
const BODY_FOLD_TOLERANCE_PX = 24;

/**
 * Whether the body is taller than the fold — measured on the element, again whenever its size changes (a narrower pane
 * makes a body taller).
 */
const useIsBodyFoldable = (bodyText: string | null) => {
  const bodyRef = useRef<HTMLDivElement>(null);
  const [isFoldable, setIsFoldable] = useState(false);
  useEffect(() => {
    const body = bodyRef.current;
    if (bodyText === null || !body) {
      setIsFoldable(false);
      return undefined;
    }
    const measureBody = () => setIsFoldable(body.scrollHeight > BODY_FOLD_HEIGHT_PX + BODY_FOLD_TOLERANCE_PX);
    measureBody();
    if (typeof ResizeObserver === "undefined") return undefined;
    const resizeObserver = new ResizeObserver(measureBody);
    resizeObserver.observe(body);
    return () => resizeObserver.disconnect();
  }, [bodyText]);
  return { bodyRef, isFoldable };
};

/**
 * `MailDraftPreview` (written by the mail tool, never by the model): the exact mail of a send step — sender, recipients,
 * subject and the body as markdown, so a list or a small table reads as one; a long body folds with "Show more".
 *
 * `DRAFT`: the step waits for the owner's yes. ONE send button; its click posts the button's label — translated, as the
 * owner reads it — as the owner's message (`sendMessageToChat`), which is the confirmation. The button stays, disabled
 * and busy, once the host accepted the send, and goes away when a later row carries the `SENT` preview of the same
 * `contentHash`. Without a click it is shown only while the message is interactive and no later owner message
 * answered it (the owner typed past it or confirmed in words).
 *
 * `SENT`: the mail went out; a "Sent" chip, no button. A block without a recipient renders nothing.
 */
export const MailDraftPreviewConversationWidget = (props: PayloadConversationWidgetProps) => {
  const { payload, interactive = false, messageId } = props;
  const { sendMessageToChat, messages } = useConversationWidgetContext();
  const texts = useAgentsTexts();
  const isEmailRender = useIsEmailWidgetRender();
  const parsedPayload = useMemo(() => parseMailDraftPreviewPayload(payload), [payload]);
  const [isSending, setIsSending] = useState(false);
  const [isSendAccepted, setIsSendAccepted] = useState(false);
  const [isExpanded, setIsExpanded] = useState(false);
  const isSendingRef = useRef(false);
  const { bodyRef, isFoldable } = useIsBodyFoldable(parsedPayload === null ? null : parsedPayload.bodyText);

  // `messages` is ordered oldest → newest: everything past the host message is a later row.
  const laterMessages = useMemo(() => {
    if (!messages || messageId === undefined) return [];
    const hostIndex = messages.findIndex((message) => String(message.id) === String(messageId));
    return hostIndex === -1 ? [] : messages.slice(hostIndex + 1);
  }, [messages, messageId]);
  const contentHash = parsedPayload === null ? "" : parsedPayload.contentHash;
  const isFollowedBySent = useMemo(
    () => laterMessages.some((message) => isSentMailDraftPreviewOf(message.message, contentHash)),
    [laterMessages, contentHash],
  );
  const isAnswered = laterMessages.some((message) => message.role === "USER");

  useReportWidgetRenderStatus(
    parsedPayload === null ? "failed" : "mounted",
    parsedPayload === null ? "INVALID_PROP" : undefined,
    parsedPayload === null ? "the block names no recipient" : undefined,
  );
  if (parsedPayload === null) return null;

  const { status, from, to, cc, bcc, subject, bodyText } = parsedPayload;
  const isDraft = status === "DRAFT";
  const isSendBusy = isSending || isSendAccepted;
  const showsSendButton =
    isDraft && !isEmailRender && !isFollowedBySent && (isSendAccepted || (interactive && !isAnswered));
  const isFolded = isFoldable && !isExpanded && !isEmailRender;

  const send = async () => {
    if (isSendingRef.current || isSendAccepted) return;
    isSendingRef.current = true;
    setIsSending(true);
    try {
      // The label the owner reads is the message: their confirmation, in their language.
      const result = await sendMessageToChat(texts.translate("Send"));
      // `true` or `void` (a host that does not report) both count as accepted.
      if (result !== false) setIsSendAccepted(true);
    } catch {
      setIsSendAccepted(false);
    } finally {
      isSendingRef.current = false;
      setIsSending(false);
    }
  };

  const renderField = (label: string, value: string, testId: string) => (
    <div className={styles.field} data-testid={testId}>
      <dt>
        <Text role="help" color="var(--agents-fg-light)">
          {label}
        </Text>
      </dt>
      <dd className={styles.fieldValue}>
        <ModelText role="help" weight="medium">
          {value}
        </ModelText>
      </dd>
    </div>
  );

  return (
    <WidgetSurface testId={TEST_ID}>
      <div className={styles.root} data-status={status}>
        <div className={styles.header}>
          <dl className={styles.fields}>
            {from !== "" ? renderField(textKey("From"), from, `${TEST_ID}-from`) : null}
            {renderField(textKey("To"), to.join(", "), `${TEST_ID}-to`)}
            {cc.length > 0 ? renderField(textKey("Cc"), cc.join(", "), `${TEST_ID}-cc`) : null}
            {bcc.length > 0 ? renderField(textKey("Bcc"), bcc.join(", "), `${TEST_ID}-bcc`) : null}
            {subject !== "" ? renderField(textKey("Subject"), subject, `${TEST_ID}-subject`) : null}
          </dl>
          {isDraft ? null : (
            <span className={styles.sentChip} data-testid={`${TEST_ID}-sent`}>
              <WidgetStatusChip tone="success">{textKey("Sent")}</WidgetStatusChip>
            </span>
          )}
        </div>
        {bodyText.trim() !== "" ? (
          <div className={styles.bodySection}>
            <div
              ref={bodyRef}
              className={classNames(styles.body, isFolded && styles.bodyFolded)}
              style={isFolded ? { maxHeight: BODY_FOLD_HEIGHT_PX } : undefined}
              data-testid={`${TEST_ID}-body`}
            >
              <Markdown markdown={bodyText} translate={false} components={MAIL_BODY_MARKDOWN_COMPONENTS} />
            </div>
            {isFoldable && !isEmailRender ? (
              <button
                type="button"
                className={styles.foldToggle}
                aria-expanded={isExpanded}
                onClick={() => setIsExpanded((wasExpanded) => !wasExpanded)}
                data-testid={`${TEST_ID}-fold-toggle`}
              >
                <Text role="caption" weight="medium" color="var(--agents-fg-light)">
                  {isExpanded ? "Show less" : "Show more"}
                </Text>
              </button>
            ) : null}
          </div>
        ) : null}
        {showsSendButton ? (
          <div className={styles.actions}>
            <Button
              size="small"
              variant="primary"
              icon="arrowRight"
              iconPosition="right"
              onClick={send}
              disabled={isSendBusy}
              isLoading={isSending}
              testId={`${TEST_ID}-send`}
            >
              {isSendBusy ? "Sending…" : "Send"}
            </Button>
          </div>
        ) : null}
      </div>
    </WidgetSurface>
  );
};
