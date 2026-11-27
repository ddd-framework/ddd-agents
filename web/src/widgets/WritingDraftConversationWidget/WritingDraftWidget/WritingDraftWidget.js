import { Text, TextArea, TextInput } from "../../../provider/components";
import { useId } from "react";
import { WidgetSurface } from "../../_shared/modern";
import { ConversationWidgetButton } from "../../_shared/ConversationWidgetButton";
import { ConversationWidgetActions } from "../../../renderer/ConversationWidgetActions/ConversationWidgetActions";
import { ModelText } from "../../../kit/ModelText/ModelText";
import { WritingDraftBody } from "./WritingDraftBody";
import styles from "./WritingDraftWidget.module.scss";
import { textKey } from "../../../provider/text-key";
/** The label above the headline per kind — one translation key each, never a concatenated word. */
export const WRITING_DRAFT_KIND_LABELS = {
    email: textKey("E-mail draft"),
    letter: textKey("Letter draft"),
    post: textKey("Post draft"),
    reply: textKey("Reply draft"),
    message: textKey("Message draft"),
    other: textKey("Text draft"),
    unknown: textKey("Text draft"),
};
/**
 * The presentational draft: the kind label with the model's headline above the card; the card reads like the message
 * itself — To and Subject as a mail header divided from the text by a hairline, then the text. The actions (Copy, Edit,
 * Open in mail) sit centred under the card as small pills. Editing happens in the same card: the header rows and the
 * text become fields, Done and Discard changes replace the actions and a note says the edit stays on this screen.
 * Knows nothing about payloads, the registry or the chat. The actions are left out of an e-mail capture
 * (`ConversationWidgetActions`).
 */
export const WritingDraftWidget = (props) => {
    const { kind, title, values, showsAddressFields, isEditing, isEdited, characterCount, isCopied, canOpenInMail, mailLinkOmitsBody, onChangeField, onStartEditing, onFinishEditing, onReset, onCopy, onOpenInMail, } = props;
    const headingId = useId();
    const kindLabel = WRITING_DRAFT_KIND_LABELS[kind ?? "unknown"];
    const headline = title !== undefined && title !== values.subject ? title : undefined;
    const showsRecipientRow = showsAddressFields || values.recipient !== "";
    const showsSubjectRow = showsAddressFields || values.subject !== "";
    const showsNotes = isEditing || isEdited || mailLinkOmitsBody;
    const renderHeaderLine = (label, value, emptyLabel, testId) => (<div className={styles.headerLine} data-testid={testId}>
      <Text role="help" color="var(--agents-fg-light)">
        {label}
      </Text>
      {value !== "" ? (<ModelText role="help" weight="medium" className={styles.wrappingText}>
          {value}
        </ModelText>) : (<Text role="help" color="var(--agents-fg-lightest)" className={styles.emptyValue}>
          {emptyLabel}
        </Text>)}
    </div>);
    const renderPill = (label, icon, onClick, testId, isPrimary = false) => (<ConversationWidgetButton variant={isPrimary ? "primary" : "muted"} size="small" rounded icon={icon} onClick={onClick} testId={testId}>
      {label}
    </ConversationWidgetButton>);
    return (<section aria-labelledby={headingId} className={styles.root} data-testid="writing-draft-widget">
      <div className={styles.titleBlock} id={headingId}>
        <Text role="intro" weight="bold" testId={headline ? "writing-draft-widget-kind" : "writing-draft-widget-title"}>
          {kindLabel}
        </Text>
        {headline ? (<ModelText role="help" color="var(--agents-fg-light)" className={styles.wrappingText} testId="writing-draft-widget-title">
            {headline}
          </ModelText>) : null}
      </div>

      <WidgetSurface className={styles.card} testId="writing-draft-widget">
        {isEditing ? (<div className={styles.editFields}>
            {showsRecipientRow ? (<TextInput testId="writing-draft-recipient-input" value={values.recipient} label={"Recipient"} onChange={(text) => onChangeField("recipient", text)}/>) : null}
            {showsSubjectRow ? (<TextInput testId="writing-draft-subject-input" value={values.subject} label={"Subject"} onChange={(text) => onChangeField("subject", text)}/>) : null}
            <TextArea testId="writing-draft-body-input" value={values.body} label={"Text of the draft"} minRows={6} rows={6} showsCounter={false} autoFocus onChange={(text) => onChangeField("body", text)}/>
          </div>) : (<>
            {showsRecipientRow || showsSubjectRow ? (<div className={styles.header}>
                {showsRecipientRow
                    ? renderHeaderLine(textKey("To"), values.recipient, textKey("No recipient yet"), "writing-draft-recipient")
                    : null}
                {showsSubjectRow
                    ? renderHeaderLine(textKey("Subject"), values.subject, textKey("No subject yet"), "writing-draft-subject")
                    : null}
              </div>) : null}
            {values.body.trim() !== "" ? (<WritingDraftBody markdown={values.body}/>) : (<Text role="help" color="var(--agents-fg-light)" className={styles.wrappingText}>
                {"The text of this draft is empty."}
              </Text>)}
          </>)}

        <ConversationWidgetActions>
          {showsNotes ? (<div className={styles.notes}>
              {isEditing || isEdited ? (<Text role="caption" color="var(--agents-fg-light)" className={styles.wrappingText}>
                  {"Your changes stay on this screen — the assistant does not see them."}
                </Text>) : null}
              {mailLinkOmitsBody ? (<Text role="caption" color="var(--agents-fg-light)" className={styles.wrappingText}>
                  {"The text is too long for a mail link: copy it and paste it into the e-mail."}
                </Text>) : null}
            </div>) : null}
        </ConversationWidgetActions>

        {isEdited || characterCount !== undefined ? (<div className={styles.meta}>
            {isEdited ? (<Text role="caption" color="var(--agents-fg-light)" testId="writing-draft-edited">
                {"Edited by you"}
              </Text>) : null}
            {characterCount !== undefined ? (<Text role="caption" color="var(--agents-fg-light)" replacements={{ count: characterCount }} context={characterCount === 1 ? "one" : "other"} testId="writing-draft-character-count">
                {"%count% character"}
              </Text>) : null}
          </div>) : null}
      </WidgetSurface>

      <ConversationWidgetActions>
        <div className={styles.actions}>
          {isEditing ? (<>
              {renderPill(textKey("Done"), "check", onFinishEditing, "writing-draft-done", true)}
              {isEdited ? renderPill(textKey("Discard changes"), "refresh", onReset, "writing-draft-reset") : null}
            </>) : (<>
              {renderPill(isCopied ? textKey("Copied") : textKey("Copy"), isCopied ? "check" : "duplicate", onCopy, "writing-draft-copy")}
              {renderPill(textKey("Edit"), "edit", onStartEditing, "writing-draft-edit")}
              {canOpenInMail ? renderPill(textKey("Open in mail"), "mail", onOpenInMail, "writing-draft-open-in-mail") : null}
              {isEdited ? renderPill(textKey("Discard changes"), "refresh", onReset, "writing-draft-reset") : null}
            </>)}
        </div>
      </ConversationWidgetActions>
    </section>);
};
