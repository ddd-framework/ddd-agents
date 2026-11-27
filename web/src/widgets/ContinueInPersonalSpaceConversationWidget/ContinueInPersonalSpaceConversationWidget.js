import { useMemo, useState } from "react";
import { Button, Icon, Text } from "../../provider/components";
import { canStartConversationInPersonalSpace, startConversationInPersonalSpace } from "../../provider/environment";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { WidgetSurface } from "../_shared/modern";
import { parseContinueInPersonalSpacePayload } from "./continue-in-personal-space-payload";
import styles from "./ContinueInPersonalSpaceConversationWidget.module.scss";
const TEST_ID = "continue-in-personal-space-widget";
/**
 * `ContinueInPersonalSpace` (written by the backend, never by the model): the owner asked for something only a
 * connection of their own may answer, in a conversation that others read. The widget says so and offers to continue in
 * the personal space: the click hands the request to the app (`AgentsEnvironment.startConversationInPersonalSpace`),
 * which starts a NEW conversation there. Nothing goes back into this conversation. Without that hook, or without a
 * request in the block, the widget renders nothing.
 */
export const ContinueInPersonalSpaceConversationWidget = (props) => {
    const { payload, interactive } = props;
    const parsedPayload = useMemo(() => parseContinueInPersonalSpacePayload(payload), [payload]);
    const [isStarted, setIsStarted] = useState(false);
    const canStart = canStartConversationInPersonalSpace();
    useReportWidgetRenderStatus(parsedPayload === null ? "failed" : "mounted", parsedPayload === null ? "INVALID_PROP" : undefined, parsedPayload === null ? "the block carries no request" : undefined);
    if (parsedPayload === null || !canStart)
        return null;
    const continueInPersonalSpace = () => {
        if (isStarted)
            return;
        if (startConversationInPersonalSpace(parsedPayload.request))
            setIsStarted(true);
    };
    return (<WidgetSurface testId={TEST_ID} compact className={styles.root}>
      <span className={styles.notice}>
        <Icon name="lock" size="small"/>
        <Text testId={`${TEST_ID}-notice`}>Only you may use this connection, and others read this conversation.</Text>
      </span>
      <Button size="small" icon="arrowRight" iconPosition="right" onClick={continueInPersonalSpace} disabled={interactive === false || isStarted} testId={`${TEST_ID}-button`}>
        Continue in your personal space
      </Button>
    </WidgetSurface>);
};
