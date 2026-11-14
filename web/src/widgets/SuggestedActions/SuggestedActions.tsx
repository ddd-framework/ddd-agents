import { Button, Text } from "../../provider/components";
import React from "react";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import styles from "./SuggestedActions.module.scss";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { BaseConversationWidgetProps } from "../../renderer/conversation-widget-props";
import {
  useReportWidgetRenderStatus,
  type WidgetRenderReportEntry,
} from "../../renderer/render-report/widget-render-report";

export interface SuggestedAction {
  displayText: string;
  userAction: string;
}

interface SuggestedActionsProps extends BaseConversationWidgetProps {
  options: string; // JSON-encoded SuggestedAction[]
}

function parseOptions(options: string): SuggestedAction[] | null {
  let parsed: unknown;
  try {
    parsed = JSON.parse(options);
  } catch {
    console.warn("[SuggestedActions] Failed to parse options JSON:", options);
    return null;
  }

  if (
    !Array.isArray(parsed) ||
    !parsed.every(
      (item) =>
        typeof item === "object" &&
        item !== null &&
        typeof (item as any).displayText === "string" &&
        typeof (item as any).userAction === "string",
    )
  ) {
    console.warn("[SuggestedActions] Options must be an array of { displayText, userAction }:", parsed);
    return null;
  }

  return parsed as SuggestedAction[];
}

/** The block's render report: an unreadable options list, an empty one, or mounted. */
const readSuggestedActionsRenderReport = (parsedOptions: SuggestedAction[] | null): WidgetRenderReportEntry => {
  if (parsedOptions === null)
    return {
      status: "failed",
      code: "INVALID_PROP",
      detail: "The SuggestedActions options must be a JSON array of { displayText, userAction }.",
    };
  if (parsedOptions.length === 0)
    return { status: "empty", code: "NO_DATA", detail: "The SuggestedActions options list is empty." };
  return { status: "mounted" };
};

const testId = "suggested-actions-widget";

export const SuggestedActions = ({ options, interactive = false }: SuggestedActionsProps) => {
  const { sendMessageToChat } = useConversationWidgetContext();
  const isEmailRender = useIsEmailWidgetRender();
  const parsedOptions = parseOptions(options);
  const renderReport = readSuggestedActionsRenderReport(parsedOptions);
  useReportWidgetRenderStatus(renderReport.status, renderReport.code, renderReport.detail);

  if (!parsedOptions) {
    return null;
  }

  return (
    <div className={styles.suggestedActions} data-testid={testId}>
      {parsedOptions.map((option, index) =>
        isEmailRender ? (
          <Text key={index} translate={false} testId={`${testId}-option-${option.userAction}`}>
            {option.displayText}
          </Text>
        ) : (
          <Button
            key={index}
            variant="muted"
            disabled={!interactive}
            onClick={() => sendMessageToChat(option.userAction)}
            testId={`${testId}-option-${option.userAction}`}
          >
            {option.displayText}
          </Button>
        ),
      )}
    </div>
  );
};
