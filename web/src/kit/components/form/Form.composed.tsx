import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { createContext, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useConversationWidgetContext } from "../../../renderer/ConversationWidgetCallbacksContext";
import { ConversationWidgetButton } from "../../../widgets/_shared/ConversationWidgetButton";
import { DonePill } from "../../../widgets/_shared/DonePill";
import { WidgetSurface } from "../../../widgets/_shared/modern";
import { ModelText } from "../../ModelText/ModelText";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import {
  collectBoundStateNames,
  composeFormDeclineSignal,
  composeFormSubmitSignal,
  isFormFieldFilled,
  isFormNameAllowed,
  readStateNameList,
} from "./compose-form-signal";
import styles from "./Form.module.scss";

/** True inside a Form's body: a nested Form gives its body to the outer form instead of submitting on its own. */
const ComposedViewFormContext = createContext(false);

/** Judgement text in the 900 tone, flipped for the dark scheme (no functional error token). */
const ERROR_TEXT_COLOR = "light-dark(var(--agents-danger-900), var(--agents-danger-500))";

type FormSubmissionStatus = "idle" | "sending" | "sent" | "declined" | "failed";

/**
 * The `Form` node (`FormComposedViewNode.php`): the kit's inputs inside one surface with a submit
 * and an optional decline. The inputs write the tree's local state as everywhere; only the submit reaches the agent — as
 * ONE FRONTEND_WIDGET_CLICK signal through `injectSignal` (`ComposedView form "<name>" submitted:` + one `- <field>:
 * <value>` line per field, `compose-form-signal.ts`; the stored contract is `composed-view-widget-contract.json`). The
 * fields are the state names of `fields`, else every state name the body binds; `requiredFields` must be filled first.
 *
 * Answered only once the host accepted the signal: then a done pill replaces the buttons and the body is disabled;
 * an undelivered signal keeps the form and offers the retry. A replayed reply shows the body without buttons (its answer
 * belongs to its own turn), the e-mail capture shows the body frozen without any action row. A name the signal cannot
 * carry and a Form inside a Form render their body and report `failed`, so the model learns the form cannot submit.
 */
const FormNode = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, slots, sizeClass, runtime, testId } = props;
  const { state, interactive, isEmailRender, reportNodeStatus } = runtime;
  const isInsideForm = useContext(ComposedViewFormContext);
  const { injectSignal } = useConversationWidgetContext();
  const [submissionStatus, setSubmissionStatus] = useState<FormSubmissionStatus>("idle");
  const [hasTriedWithMissingFields, setHasTriedWithMissingFields] = useState(false);
  const isSignalInFlightRef = useRef(false);

  const formName = isFormNameAllowed(nodeProps.name) ? nodeProps.name : undefined;
  const fieldNames = useMemo(
    () =>
      Array.isArray(node.props.fields)
        ? readStateNameList(node.props.fields, state)
        : collectBoundStateNames(node).filter((stateName) => Object.prototype.hasOwnProperty.call(state, stateName)),
    [node, state],
  );
  const requiredFieldNames = useMemo(() => readStateNameList(node.props.requiredFields, state), [node, state]);
  const hasMissingRequiredField = requiredFieldNames.some((stateName) => !isFormFieldFilled(state[stateName]));

  useEffect(() => {
    if (isInsideForm) {
      reportNodeStatus(
        "failed",
        "INVALID_NODE",
        "a Form inside a Form has no submit of its own — its inputs belong to the outer form",
      );
    } else if (formName === undefined) {
      reportNodeStatus(
        "failed",
        "INVALID_PROP",
        'a Form name is letters, digits, "_" or "-", at most 64 — this form renders but cannot submit',
      );
    }
  }, [isInsideForm, formName, reportNodeStatus]);

  if (isInsideForm)
    return (
      <div className={styles.nestedBody} data-testid={testId}>
        {slots.children}
      </div>
    );

  const canSend = formName !== undefined && interactive && !isEmailRender;
  const isAnswered = submissionStatus === "sent" || submissionStatus === "declined";

  /** One signal at a time; the form counts as answered only once the host accepted it. */
  const sendSignal = async (signalText: string, answeredStatus: "sent" | "declined") => {
    if (isSignalInFlightRef.current) return;
    isSignalInFlightRef.current = true;
    setSubmissionStatus("sending");
    try {
      const accepted = await injectSignal(signalText);
      setSubmissionStatus(accepted === true ? answeredStatus : "failed");
    } catch {
      setSubmissionStatus("failed");
    } finally {
      isSignalInFlightRef.current = false;
    }
  };

  const submit = () => {
    if (!canSend || formName === undefined || isAnswered) return;
    if (hasMissingRequiredField) {
      setHasTriedWithMissingFields(true);
      return;
    }
    void sendSignal(
      composeFormSubmitSignal(
        formName,
        fieldNames.map((fieldName) => [fieldName, state[fieldName]] as const),
      ),
      "sent",
    );
  };

  const decline = () => {
    if (!canSend || formName === undefined || isAnswered) return;
    void sendSignal(composeFormDeclineSignal(formName), "declined");
  };

  const title = typeof nodeProps.title === "string" && nodeProps.title.trim() !== "" ? nodeProps.title : undefined;
  const description =
    typeof nodeProps.description === "string" && nodeProps.description.trim() !== ""
      ? nodeProps.description
      : undefined;
  const submitLabel =
    typeof nodeProps.submitLabel === "string" && nodeProps.submitLabel.trim() !== ""
      ? nodeProps.submitLabel
      : undefined;
  const declineLabel =
    typeof nodeProps.declineLabel === "string" && nodeProps.declineLabel.trim() !== ""
      ? nodeProps.declineLabel
      : undefined;
  const isSending = submissionStatus === "sending";
  const headerItems = slots.header ?? [];
  const showsMissingFields = hasTriedWithMissingFields && hasMissingRequiredField;

  const renderActionRow = () => {
    if (isEmailRender || formName === undefined) return null;
    if (submissionStatus === "sent") return <DonePill testId={`${testId}-done`}>{"Sent"}</DonePill>;
    if (submissionStatus === "declined")
      return (
        <DonePill status="neutral" testId={`${testId}-done`}>
          {"Declined"}
        </DonePill>
      );
    if (!interactive) {
      return (
        <Text role="caption" color="var(--agents-fg-light)" testId={`${testId}-replay-note`}>
          {"This form can only be sent from the newest reply."}
        </Text>
      );
    }
    return (
      <div className={styles.actions}>
        <ConversationWidgetButton
          variant="primary"
          size="medium"
          isLoading={isSending}
          disabled={isSending}
          translate={submitLabel === undefined}
          onClick={submit}
          testId={`${testId}-submit`}
        >
          {submitLabel === undefined ? "Send" : escapePlainTextForText(submitLabel)}
        </ConversationWidgetButton>
        {declineLabel !== undefined ? (
          <ConversationWidgetButton
            variant="muted"
            // Every type shares the muted size set, so submit and decline are both medium.
            size="medium"
            disabled={isSending}
            translate={false}
            onClick={decline}
            testId={`${testId}-decline`}
          >
            {escapePlainTextForText(declineLabel)}
          </ConversationWidgetButton>
        ) : null}
      </div>
    );
  };

  return (
    <WidgetSurface level={sizeClass === "full" ? "elevated" : "flat"} testId={testId}>
      {/* The browser never submits: the component library buttons are submit buttons, the body's own buttons included — only the
          action row's onClick sends. */}
      <form className={styles.form} noValidate onSubmit={(submitEvent) => submitEvent.preventDefault()}>
        {title !== undefined || description !== undefined || headerItems.length > 0 ? (
          <div className={styles.headerRow}>
            <div className={styles.header}>
              {title !== undefined ? (
                <ModelText role="body" weight="bold">
                  {title}
                </ModelText>
              ) : null}
              {description !== undefined ? (
                <ModelText role="help" color="var(--agents-fg-light)">
                  {description}
                </ModelText>
              ) : null}
            </div>
            {headerItems.length > 0 ? <div className={styles.headerItems}>{headerItems}</div> : null}
          </div>
        ) : null}
        {/* Disabled while the answer is on its way and after it was given; the e-mail capture keeps the normal look
            (its page is inert and the renderer freezes the state). */}
        <ComposedViewFormContext.Provider value={true}>
          <fieldset className={styles.fields} disabled={isAnswered || isSending}>
            {slots.children}
          </fieldset>
        </ComposedViewFormContext.Provider>
        <div className={styles.footer} aria-live="polite">
          {showsMissingFields ? (
            <Text role="caption" color={ERROR_TEXT_COLOR} testId={`${testId}-error-required`}>
              {"Please fill in the required fields."}
            </Text>
          ) : null}
          {submissionStatus === "failed" ? (
            <Text role="caption" color={ERROR_TEXT_COLOR} testId={`${testId}-error`}>
              {"Something went wrong. Please try again."}
            </Text>
          ) : null}
          {renderActionRow()}
        </div>
      </form>
    </WidgetSurface>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Form", component: FormNode };
