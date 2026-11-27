import { Button, Text } from "../../provider/components";
import React from "react";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import styles from "./SuggestedActions.module.scss";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { useReportWidgetRenderStatus, } from "../../renderer/render-report/widget-render-report";
function parseOptions(options) {
    let parsed;
    try {
        parsed = JSON.parse(options);
    }
    catch {
        console.warn("[SuggestedActions] Failed to parse options JSON:", options);
        return null;
    }
    if (!Array.isArray(parsed) ||
        !parsed.every((item) => typeof item === "object" &&
            item !== null &&
            typeof item.displayText === "string" &&
            typeof item.userAction === "string")) {
        console.warn("[SuggestedActions] Options must be an array of { displayText, userAction }:", parsed);
        return null;
    }
    return parsed;
}
/** The block's render report: an unreadable options list, an empty one, or mounted. */
const readSuggestedActionsRenderReport = (parsedOptions) => {
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
export const SuggestedActions = ({ options, interactive = false }) => {
    const { sendMessageToChat } = useConversationWidgetContext();
    const isEmailRender = useIsEmailWidgetRender();
    const parsedOptions = parseOptions(options);
    const renderReport = readSuggestedActionsRenderReport(parsedOptions);
    useReportWidgetRenderStatus(renderReport.status, renderReport.code, renderReport.detail);
    if (!parsedOptions) {
        return null;
    }
    return (<div className={styles.suggestedActions} data-testid={testId}>
      {parsedOptions.map((option, index) => isEmailRender ? (<Text key={index} translate={false} testId={`${testId}-option-${option.userAction}`}>
            {option.displayText}
          </Text>) : (<Button key={index} variant="muted" disabled={!interactive} onClick={() => sendMessageToChat(option.userAction)} testId={`${testId}-option-${option.userAction}`}>
            {option.displayText}
          </Button>))}
    </div>);
};
