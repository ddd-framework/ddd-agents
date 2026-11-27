import { useMemo } from "react";
import { Button, Icon, Text } from "../../provider/components";
import { canOpenConnectionPermissions, openConnectionPermissions } from "../../provider/environment";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { WidgetSurface } from "../_shared/modern";
import { parseExtendConnectionPermissionPayload } from "./extend-connection-permission-payload";
import styles from "./ExtendConnectionPermissionConversationWidget.module.scss";

const TEST_ID = "extend-connection-permission-widget";

/**
 * `ExtendConnectionPermission` (written by the backend, never by the model): a step needs a level the connection does
 * not have, or a permission the provider has not granted yet. The widget says so and offers to open the connection's
 * settings with that permission preselected (`AgentsEnvironment.openConnectionPermissions`); the level takes effect only
 * once the owner raised it there. The button may be used again (the owner may close the settings unchanged). Without
 * the hook, or without a connection, capability and level in the block, the widget renders nothing.
 */
export const ExtendConnectionPermissionConversationWidget = (props: PayloadConversationWidgetProps) => {
  const { payload, interactive } = props;
  const parsedPayload = useMemo(() => parseExtendConnectionPermissionPayload(payload), [payload]);
  const canOpen = canOpenConnectionPermissions();
  useReportWidgetRenderStatus(
    parsedPayload === null ? "failed" : "mounted",
    parsedPayload === null ? "INVALID_PROP" : undefined,
    parsedPayload === null ? "the block names no connection, capability and level" : undefined,
  );
  if (parsedPayload === null || !canOpen) return null;

  return (
    <WidgetSurface testId={TEST_ID} compact className={styles.root}>
      <span className={styles.notice}>
        <Icon name="lockOpen" size="small" />
        <Text testId={`${TEST_ID}-notice`}>This step needs a permission the connection does not have yet.</Text>
      </span>
      <Button
        size="small"
        variant="default"
        icon="settings"
        onClick={() => openConnectionPermissions(parsedPayload.target)}
        disabled={interactive === false}
        testId={`${TEST_ID}-button`}
      >
        Extend the permission
      </Button>
    </WidgetSurface>
  );
};
