import { Text } from "../../provider/components";
import { useCallback, useState } from "react";
import type { CanvasBodyProps } from "../../renderer/canvas/canvas-body-props";
import { readEntityDetailInstanceId } from "../../renderer/canvas/entity-detail-instance";
import { EntityMentionAskButton } from "../../widgets/EntityMentionConversationWidget/EntityMentionAskButton/EntityMentionAskButton";
import { useEntityDetailBodies } from "./entity-detail-bodies";
import { EntityDetailNameReportContext } from "./entity-detail-name";
import styles from "./EntityDetailCanvasBody.module.scss";

/**
 * The `entityDetail` CANVAS BODY (`canvasBodies.tsx`): the shell around one entity's detail. It reads
 * WHICH entity from the canvas instance id (`entityDetail:<entityType>:<id>`) and mounts the body registered for that
 * entity type; the body self-hydrates by id. An entity type without a body renders a short notice instead of nothing,
 * so a click never lands on an empty pane. "Ask about this" stays at the pane's foot while the detail scrolls and
 * names the entity in its chip once the body reported the name (`useReportEntityDetailName`).
 */
export const EntityDetailCanvasBody = ({ canvas, agentSpaceKey }: CanvasBodyProps) => {
  const bodies = useEntityDetailBodies();
  const [reportedEntity, setReportedEntity] = useState<{ instanceId: string; name: string | undefined }>();
  const reportEntityName = useCallback(
    (entityName: string | undefined) => setReportedEntity({ instanceId: canvas.instanceId, name: entityName }),
    [canvas.instanceId],
  );
  const target = readEntityDetailInstanceId(canvas.instanceId);
  if (target === undefined) return null;
  const definition = Object.prototype.hasOwnProperty.call(bodies, target.entityType)
    ? bodies[target.entityType]
    : undefined;
  if (!definition) {
    return (
      <div className={styles.entityDetail} data-testid="entity-detail-canvas">
        <Text role="help" color="var(--agents-fg-light)" testId="entity-detail-canvas-unsupported">
          {"This detail cannot be shown here yet."}
        </Text>
      </div>
    );
  }
  const Body = definition.component;
  // A name reported for the previous entity never labels the next one's chip.
  const entityName = reportedEntity?.instanceId === canvas.instanceId ? reportedEntity.name : undefined;
  return (
    <div className={styles.entityDetail} data-testid="entity-detail-canvas">
      <EntityDetailNameReportContext.Provider value={reportEntityName}>
        <Body entityType={target.entityType} entityId={target.entityId} agentSpaceKey={agentSpaceKey} />
      </EntityDetailNameReportContext.Provider>
      <div className={styles.entityDetailFooter}>
        <EntityMentionAskButton
          entityType={target.entityType}
          entityId={target.entityId}
          label={entityName}
          agentSpaceKey={agentSpaceKey}
        />
      </div>
    </div>
  );
};
