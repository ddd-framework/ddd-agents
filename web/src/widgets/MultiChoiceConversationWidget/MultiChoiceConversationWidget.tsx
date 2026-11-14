import { Button, Checkbox, InteractiveSurface, Text } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import { escapePlainTextForText } from "../../provider/environment";
import React, { useMemo, useState } from "react";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { appendAnotherAgentSpaceToOwnerText } from "../../provider/environment";
import { readWidgetAgentSpaceKeyAttribute } from "../../renderer/_agent-space/widget-agent-space-key-attribute";
import { useResolvedConversationWidgetAgentSpaceKey } from "../../renderer/_agent-space/use-resolved-conversation-widget-agent-space-key";
import { ConversationWidgetAgentSpaceFrame } from "../../renderer/_agent-space/ConversationWidgetAgentSpaceFrame";
import { translateText } from "../../provider/environment";
import styles from "./MultiChoiceConversationWidget.module.scss";
import { parseMultiChoicePayload } from "./shared/multi-choice-widget-payload";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";

// Convert the `{count}` template token to the `%count%` placeholder the translation
// service replaces, so the confirm label stays one translatable key (singular, with
// its MANY value) instead of a per-count string baked in JS.
const toConfirmLabelKey = (template: string): string => template.replace(/\{count\}/g, "%count%");

const formatConfirmAction = (template: string, values: string[]): string =>
  template.replace(/\{values\}/g, values.join(", ")).replace(/\{count\}/g, String(values.length));

const testId = "multi-choice-widget";

export const MultiChoiceConversationWidget = (props: PayloadConversationWidgetProps) => {
  const { payload, fresh = false } = props;
  const { sendMessageToChat } = useConversationWidgetContext();
  const isEmailRender = useIsEmailWidgetRender();
  const { anotherAgentSpaceKey, isAnotherAgentSpace } = useResolvedConversationWidgetAgentSpaceKey(
    readWidgetAgentSpaceKeyAttribute(props),
  );
  const data = useMemo(() => parseMultiChoicePayload(payload), [payload]);

  const initialSelected = useMemo(
    () => new Set((data?.options ?? []).filter((o) => o.selected).map((o) => o.value)),
    [data],
  );
  const [selected, setSelected] = useState<Set<string>>(initialSelected);
  const [confirmed, setConfirmed] = useState(false);
  const isUnreadable = data === null;
  useReportWidgetRenderStatus(
    isUnreadable ? "failed" : "mounted",
    isUnreadable ? "INVALID_PROP" : undefined,
    isUnreadable ? "The MultiChoice payload is not an object with an options list." : undefined,
  );

  // History replay: the choice is a one-shot interactive prompt, so a stale
  // remount renders nothing rather than re-offering an answered choice.
  if (!fresh && !isEmailRender) return null;

  if (!data) return null;

  const { title, subtitle, options } = data;
  const selectAllLabel = data.selectAllLabel ?? "Select all";
  const confirmLabelTemplate = data.confirmLabelTemplate ?? "Confirm {count} selection";
  const confirmAction = data.confirmAction ?? "Confirmed: {values}";

  const allSelected = options.length > 0 && selected.size === options.length;

  const toggle = (value: string) => {
    if (confirmed) return;
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(value)) next.delete(value);
      else next.add(value);
      return next;
    });
  };

  const handleSelectAll = () => {
    if (confirmed) return;
    setSelected(allSelected ? new Set() : new Set(options.map((o) => o.value)));
  };

  const handleConfirm = () => {
    if (confirmed || selected.size === 0) return;
    const values = options.filter((o) => selected.has(o.value)).map((o) => o.label);
    setConfirmed(true);
    const action = formatConfirmAction(confirmAction, values);
    sendMessageToChat?.(
      isAnotherAgentSpace && anotherAgentSpaceKey !== undefined
        ? appendAnotherAgentSpaceToOwnerText(action, anotherAgentSpaceKey)
        : action,
    );
  };

  return (
    <ConversationWidgetAgentSpaceFrame anotherAgentSpaceKey={isAnotherAgentSpace ? anotherAgentSpaceKey : undefined}>
      <div className={styles.wrap} data-testid={testId}>
        {(title || subtitle) && (
          <div className={styles.heading} data-testid={`${testId}-heading`}>
            {title ? (
              <Text
                role="intro"
                weight="bold"
                translate={false}
                testId={`${testId}-title`}
              >
                {escapePlainTextForText(title)}
              </Text>
            ) : null}
            {subtitle ? (
              <Text
                role="caption"
                color="var(--agents-fg-light)"
                translate={false}
                testId={`${testId}-subtitle`}
              >
                {escapePlainTextForText(subtitle)}
              </Text>
            ) : null}
          </div>
        )}

        <div className={styles.options} data-testid={`${testId}-options`}>
          {options.map((option) => {
            const isSelected = selected.has(option.value);
            return (
              <InteractiveSurface
                hoverable
                key={option.value}
                className={classNames(styles.option, isSelected ? styles.optionSelected : undefined)}
              >
                <div
                  className={styles.optionControl}
                  data-testid={`${testId}-option-${option.value}`}
                  onClick={isEmailRender ? undefined : () => toggle(option.value)}
                  role={isEmailRender ? undefined : "checkbox"}
                  aria-checked={isEmailRender ? undefined : isSelected}
                  tabIndex={isEmailRender || confirmed ? -1 : 0}
                  onKeyDown={(e) => {
                    if (!isEmailRender && (e.key === " " || e.key === "Enter")) {
                      e.preventDefault();
                      toggle(option.value);
                    }
                  }}
                >
                  <div className={styles.optionBody}>
                    <Text
                      role="body"
                      weight="bold"
                      className={isSelected ? styles.optionLabelSelected : styles.optionLabel}
                      translate={false}
                    >
                      {escapePlainTextForText(option.label)}
                    </Text>
                    {option.sublabel ? (
                      <Text role="caption" color="var(--agents-fg-light)" translate={false}>
                        {escapePlainTextForText(option.sublabel)}
                      </Text>
                    ) : null}
                  </div>
                  {!isEmailRender && (
                    <Checkbox
                      checked={isSelected}
                      disabled={confirmed}
                      onChange={() => toggle(option.value)}
                      testId={`${testId}-option-${option.value}-checkbox`}
                    />
                  )}
                </div>
              </InteractiveSurface>
            );
          })}
        </div>

        {!isEmailRender && (
          <div className={styles.footer}>
            <Button
              variant="muted"
              onClick={handleSelectAll}
              disabled={confirmed}
              testId={`${testId}-select-all`}
            >
              {allSelected ? "Clear all" : selectAllLabel}
            </Button>
            {/* Button takes no `context`: resolve the singular key or its MANY value here. */}
            <Button size="extraLarge"
              variant="shimmer"
              onClick={handleConfirm}
              disabled={confirmed || selected.size === 0}
              translate={false}
              testId={`${testId}-confirm`}
            >
              {
                // Nothing picked yet: "Confirm 0 selection" read as a defect — the disabled button says what it does.
                selected.size === 0 && data.confirmLabelTemplate === undefined
                  ? translateText("Confirm selection")
                  : translateText(
                      toConfirmLabelKey(confirmLabelTemplate),
                      { count: selected.size },
                      selected.size === 1 ? "one" : "other",
                    )
              }
            </Button>
          </div>
        )}
      </div>
    </ConversationWidgetAgentSpaceFrame>
  );
};
