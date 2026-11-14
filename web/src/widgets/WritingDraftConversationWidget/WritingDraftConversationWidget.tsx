import { Text } from "../../provider/components";
import { useEffect, useMemo, useState } from "react";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { parseWritingDraftPayload, type WritingDraftPayload } from "./shared/writing-draft-widget-payload";
import { convertDraftMarkdownToPlainText } from "./shared/draft-plain-text";
import { buildDraftMailLink } from "./shared/draft-mail-link";
import {
  WritingDraftWidget,
  type WritingDraftField,
  type WritingDraftValues,
} from "./WritingDraftWidget/WritingDraftWidget";
import styles from "./WritingDraftConversationWidget.module.scss";

type WritingDraftConversationWidgetProps = PayloadConversationWidgetProps;

/** How long the copy button says "Copied". */
const COPIED_CONFIRMATION_MS = 2000;

const readDraftValues = (writingDraftPayload: WritingDraftPayload): WritingDraftValues => ({
  recipient: writingDraftPayload.recipient ?? "",
  subject: writingDraftPayload.subject ?? "",
  body: writingDraftPayload.body,
});

const areDraftValuesEqual = (firstValues: WritingDraftValues, secondValues: WritingDraftValues): boolean =>
  firstValues.recipient === secondValues.recipient &&
  firstValues.subject === secondValues.subject &&
  firstValues.body === secondValues.body;

/** Opens the mail program through the `mailto:` link — the page itself stays where it is. */
const openMailLink = (href: string): void => {
  window.location.assign(href);
};

interface WritingDraftEditorProps {
  writingDraftPayload: WritingDraftPayload;
}

/**
 * The draft with its local edit state. Keyed by the payload in the outer widget, so a new block (a reworked draft the
 * model sends in a later reply renders its own widget anyway) starts from the model's text, never from an old edit.
 */
const WritingDraftEditor = (props: WritingDraftEditorProps) => {
  const { writingDraftPayload } = props;
  const originalValues = useMemo(() => readDraftValues(writingDraftPayload), [writingDraftPayload]);
  const [values, setValues] = useState<WritingDraftValues>(originalValues);
  const [isEditing, setIsEditing] = useState(false);
  const [isCopied, setIsCopied] = useState(false);

  useEffect(() => {
    if (!isCopied) return;
    const confirmationTimer = setTimeout(() => setIsCopied(false), COPIED_CONFIRMATION_MS);
    return () => clearTimeout(confirmationTimer);
  }, [isCopied]);

  const plainTextBody = useMemo(() => convertDraftMarkdownToPlainText(values.body), [values.body]);
  const isEmailDraft = writingDraftPayload.kind === "email";
  const draftMailLink = useMemo(
    () => (isEmailDraft ? buildDraftMailLink(values.recipient, values.subject, plainTextBody) : undefined),
    [isEmailDraft, values.recipient, values.subject, plainTextBody],
  );

  const changeField = (field: WritingDraftField, value: string) =>
    setValues((previousValues) => ({ ...previousValues, [field]: value }));

  const copyDraft = () => {
    void navigator.clipboard
      ?.writeText(plainTextBody)
      .then(() => setIsCopied(true))
      .catch(() => undefined);
  };

  const resetDraft = () => {
    setValues(originalValues);
    setIsEditing(false);
  };

  return (
    <WritingDraftWidget
      kind={writingDraftPayload.kind}
      title={writingDraftPayload.title}
      values={values}
      showsAddressFields={isEmailDraft || writingDraftPayload.kind === "letter"}
      isEditing={isEditing}
      isEdited={!areDraftValuesEqual(values, originalValues)}
      characterCount={writingDraftPayload.kind === "post" ? plainTextBody.length : undefined}
      isCopied={isCopied}
      canOpenInMail={draftMailLink !== undefined}
      mailLinkOmitsBody={draftMailLink !== undefined && plainTextBody !== "" && !draftMailLink.includesBody}
      onChangeField={changeField}
      onStartEditing={() => setIsEditing(true)}
      onFinishEditing={() => setIsEditing(false)}
      onReset={resetDraft}
      onCopy={copyDraft}
      onOpenInMail={() => draftMailLink && openMailLink(draftMailLink.href)}
    />
  );
};

/**
 * The `WritingDraft` widget (ChatGPT's `WritingBlock`): a text the model drafted for the owner to
 * send or publish themselves — an e-mail, a letter, a post, a reply, a message. DATA-COMPLETE: the block carries the
 * whole draft (kind, title, recipient, subject, the body as Markdown); nothing is fetched. The owner edits it in place,
 * copies the plain text and opens an e-mail draft in their own mail program (`mailto:`).
 *
 * Nothing leaves the screen: an edit, a copy or the mail link sends nothing to the agent (no signal, no message — the
 * widget is not answerable) and nothing to anyone else; the footer says so while the owner edits. In a report e-mail it
 * renders the draft read-only, without actions. A block without a text reports `empty`; an unreadable payload or a `body` that is no text reports `failed`.
 */
export const WritingDraftConversationWidget = (props: WritingDraftConversationWidgetProps) => {
  const { payload } = props;
  const writingDraftPayload = useMemo(() => parseWritingDraftPayload(payload), [payload]);
  const hasBody = writingDraftPayload.body.trim() !== "";

  const isUnreadable = !writingDraftPayload.isValidPayload || writingDraftPayload.isBodyUnreadable;
  useReportWidgetRenderStatus(
    isUnreadable ? "failed" : hasBody ? "mounted" : "empty",
    isUnreadable ? "INVALID_PROP" : hasBody ? undefined : "NO_DATA",
    !writingDraftPayload.isValidPayload
      ? "The WritingDraft payload is not a JSON object."
      : writingDraftPayload.isBodyUnreadable
        ? "The WritingDraft `body` is not a text; write the draft as one Markdown string."
        : hasBody
          ? undefined
          : "The WritingDraft widget carries no text (`body`).",
  );

  if (isUnreadable) {
    return (
      <Text
        role="help"
        color="var(--agents-fg-light)"
        className={styles.notice}
        testId="writing-draft-widget-error"
      >
        {"This draft could not be shown."}
      </Text>
    );
  }
  if (!hasBody) {
    return (
      <Text
        role="help"
        color="var(--agents-fg-light)"
        className={styles.notice}
        testId="writing-draft-widget-empty"
      >
        {"This draft has no text yet."}
      </Text>
    );
  }
  return <WritingDraftEditor key={payload} writingDraftPayload={writingDraftPayload} />;
};
