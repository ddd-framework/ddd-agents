import { Text, TextArea, TextInput, type AgentsIconName } from "../../../provider/components";
import { useId } from "react";
import { WidgetSurface } from "../../_shared/modern";
import { ConversationWidgetButton } from "../../_shared/ConversationWidgetButton";
import { ConversationWidgetActions } from "../../../renderer/ConversationWidgetActions/ConversationWidgetActions";
import { ModelText } from "../../../kit/ModelText/ModelText";
import type { WritingDraftKind } from "../shared/writing-draft-widget-payload";
import { WritingDraftBody } from "./WritingDraftBody";
import styles from "./WritingDraftWidget.module.scss";

/** The draft as the widget shows it — the model's draft, or the owner's edit of it. */
export interface WritingDraftValues {
  recipient: string;
  subject: string;
  body: string;
}

export type WritingDraftField = keyof WritingDraftValues;

/** The label above the headline per kind — one translation key each, never a concatenated word. */
export const WRITING_DRAFT_KIND_LABELS: Record<WritingDraftKind | "unknown", string> = {
  email: "E-mail draft",
  letter: "Letter draft",
  post: "Post draft",
  reply: "Reply draft",
  message: "Message draft",
  other: "Text draft",
  unknown: "Text draft",
};

export interface WritingDraftWidgetProps {
  kind?: WritingDraftKind;
  /** The model's headline; shown under the kind label when it says more than the subject already does. */
  title?: string;
  values: WritingDraftValues;
  /** Recipient and subject rows are part of this kind (an e-mail, a letter) even while empty. */
  showsAddressFields: boolean;
  isEditing: boolean;
  /** The owner changed something; Reset brings the model's draft back. */
  isEdited: boolean;
  /** A post shows its length (characters of the plain text); undefined for the other kinds. */
  characterCount?: number;
  /** The copy button confirms for a moment after a copy. */
  isCopied: boolean;
  /** Offered for an e-mail draft only. */
  canOpenInMail: boolean;
  /** The mail link leaves the body out (too long): the owner is told to copy it. */
  mailLinkOmitsBody: boolean;
  onChangeField: (field: WritingDraftField, value: string) => void;
  onStartEditing: () => void;
  onFinishEditing: () => void;
  onReset: () => void;
  onCopy: () => void;
  onOpenInMail: () => void;
}

/**
 * The presentational draft: the kind label with the model's headline above the card; the card reads like the message
 * itself — To and Subject as a mail header divided from the text by a hairline, then the text. The actions (Copy, Edit,
 * Open in mail) sit centred under the card as small pills. Editing happens in the same card: the header rows and the
 * text become fields, Done and Discard changes replace the actions and a note says the edit stays on this screen.
 * Knows nothing about payloads, the registry or the chat. The actions are left out of an e-mail capture
 * (`ConversationWidgetActions`).
 */
export const WritingDraftWidget = (props: WritingDraftWidgetProps) => {
  const {
    kind,
    title,
    values,
    showsAddressFields,
    isEditing,
    isEdited,
    characterCount,
    isCopied,
    canOpenInMail,
    mailLinkOmitsBody,
    onChangeField,
    onStartEditing,
    onFinishEditing,
    onReset,
    onCopy,
    onOpenInMail,
  } = props;
  const headingId = useId();
  const kindLabel = WRITING_DRAFT_KIND_LABELS[kind ?? "unknown"];
  const headline = title !== undefined && title !== values.subject ? title : undefined;
  const showsRecipientRow = showsAddressFields || values.recipient !== "";
  const showsSubjectRow = showsAddressFields || values.subject !== "";
  const showsNotes = isEditing || isEdited || mailLinkOmitsBody;

  const renderHeaderLine = (label: string, value: string, emptyLabel: string, testId: string) => (
    <div className={styles.headerLine} data-testid={testId}>
      <Text role="help" color="var(--agents-fg-light)">
        {label}
      </Text>
      {value !== "" ? (
        <ModelText role="help" weight="medium" className={styles.wrappingText}>
          {value}
        </ModelText>
      ) : (
        <Text role="help" color="var(--agents-fg-lightest)" className={styles.emptyValue}>
          {emptyLabel}
        </Text>
      )}
    </div>
  );

  const renderPill = (label: string, icon: AgentsIconName, onClick: () => void, testId: string, isPrimary = false) => (
    <ConversationWidgetButton
      variant={isPrimary ? "primary" : "muted"}
      size="small"
      rounded
      icon={icon}
      onClick={onClick}
      testId={testId}
    >
      {label}
    </ConversationWidgetButton>
  );

  return (
    <section aria-labelledby={headingId} className={styles.root} data-testid="writing-draft-widget">
      <div className={styles.titleBlock} id={headingId}>
        <Text
          role="intro"
          weight="bold"
          testId={headline ? "writing-draft-widget-kind" : "writing-draft-widget-title"}
        >
          {kindLabel}
        </Text>
        {headline ? (
          <ModelText role="help" color="var(--agents-fg-light)" className={styles.wrappingText} testId="writing-draft-widget-title">
            {headline}
          </ModelText>
        ) : null}
      </div>

      <WidgetSurface className={styles.card} testId="writing-draft-widget-card">
        {isEditing ? (
          <div className={styles.editFields}>
            {showsRecipientRow ? (
              <TextInput
                testId="writing-draft-recipient-input"
                value={values.recipient}
                label={"Recipient"}
                onChange={(text) => onChangeField("recipient", text)}
              />
            ) : null}
            {showsSubjectRow ? (
              <TextInput
                testId="writing-draft-subject-input"
                value={values.subject}
                label={"Subject"}
                onChange={(text) => onChangeField("subject", text)}
              />
            ) : null}
            <TextArea
              testId="writing-draft-body-input"
              value={values.body}
              label={"Text of the draft"}
              minRows={6}
              rows={6}
              showsCounter={false}
              autoFocus
              onChange={(text) => onChangeField("body", text)}
            />
          </div>
        ) : (
          <>
            {showsRecipientRow || showsSubjectRow ? (
              <div className={styles.header}>
                {showsRecipientRow
                  ? renderHeaderLine("To", values.recipient, "No recipient yet", "writing-draft-recipient")
                  : null}
                {showsSubjectRow
                  ? renderHeaderLine("Subject", values.subject, "No subject yet", "writing-draft-subject")
                  : null}
              </div>
            ) : null}
            {values.body.trim() !== "" ? (
              <WritingDraftBody markdown={values.body} />
            ) : (
              <Text role="help" color="var(--agents-fg-light)" className={styles.wrappingText}>
                {"The text of this draft is empty."}
              </Text>
            )}
          </>
        )}

        <ConversationWidgetActions>
          {showsNotes ? (
            <div className={styles.notes}>
              {isEditing || isEdited ? (
                <Text role="caption" color="var(--agents-fg-light)" className={styles.wrappingText}>
                  {"Your changes stay on this screen — the assistant does not see them."}
                </Text>
              ) : null}
              {mailLinkOmitsBody ? (
                <Text role="caption" color="var(--agents-fg-light)" className={styles.wrappingText}>
                  {"The text is too long for a mail link: copy it and paste it into the e-mail."}
                </Text>
              ) : null}
            </div>
          ) : null}
        </ConversationWidgetActions>

        {isEdited || characterCount !== undefined ? (
          <div className={styles.meta}>
            {isEdited ? (
              <Text role="caption" color="var(--agents-fg-light)" testId="writing-draft-edited">
                {"Edited by you"}
              </Text>
            ) : null}
            {characterCount !== undefined ? (
              <Text
                role="caption"
                color="var(--agents-fg-light)"
                replacements={{ count: characterCount }}
                context={characterCount === 1 ? "one" : "other"}
                testId="writing-draft-character-count"
              >
                {"%count% character"}
              </Text>
            ) : null}
          </div>
        ) : null}
      </WidgetSurface>

      <ConversationWidgetActions>
        <div className={styles.actions}>
          {isEditing ? (
            <>
              {renderPill("Done", "check", onFinishEditing, "writing-draft-done", true)}
              {isEdited ? renderPill("Discard changes", "refresh", onReset, "writing-draft-reset") : null}
            </>
          ) : (
            <>
              {renderPill(isCopied ? "Copied" : "Copy", isCopied ? "check" : "duplicate", onCopy, "writing-draft-copy")}
              {renderPill("Edit", "edit", onStartEditing, "writing-draft-edit")}
              {canOpenInMail ? renderPill("Open in mail", "mail", onOpenInMail, "writing-draft-open-in-mail") : null}
              {isEdited ? renderPill("Discard changes", "refresh", onReset, "writing-draft-reset") : null}
            </>
          )}
        </div>
      </ConversationWidgetActions>
    </section>
  );
};
