import { Text } from "../provider/components";
import type {
  ComposedViewComponentDefinition,
  ComposedViewComponentProps,
} from "../kit/composed-view-component-types";
import {
  buildComposedViewComponentRegistry,
  composedViewComponents,
} from "../kit/composed-view-components";
import { WidgetSurface } from "../widgets/_shared/modern";
import styles from "./WidgetPreview.module.scss";

/**
 * Stand-ins for kit node types that have a schema but no component yet — only the preview uses
 * them, and only for a fixture that asks (`stubComposedViewComponents`). A real component always wins.
 */
const formatDuration = (durationSeconds: unknown): string | null => {
  if (typeof durationSeconds !== "number" || !Number.isFinite(durationSeconds) || durationSeconds < 0) return null;
  const minutes = Math.floor(durationSeconds / 60);
  const seconds = Math.floor(durationSeconds % 60);
  return `${minutes}:${String(seconds).padStart(2, "0")}`;
};

const StubVideo = ({ props }: ComposedViewComponentProps) => {
  const duration = formatDuration(props.durationSeconds);
  return (
    <WidgetSurface level="flat" compact>
      <div className={styles.stubVideo}>
        {typeof props.thumbnail === "string" ? (
          <img className={styles.stubVideoThumbnail} src={props.thumbnail} alt="" />
        ) : null}
        <div className={styles.stubVideoText}>
          <Text role="body" weight="bold" translate={false}>
            {typeof props.title === "string" ? props.title : ""}
          </Text>
          <Text role="caption" color="var(--agents-fg-light)" translate={false}>
            {[typeof props.channel === "string" ? props.channel : null, duration].filter(Boolean).join(" · ")}
          </Text>
        </div>
      </div>
    </WidgetSurface>
  );
};

const PREVIEW_STUB_COMPONENTS: ComposedViewComponentDefinition[] = [{ nodeType: "Video", component: StubVideo }];

/** The folder registry with a stub for every node type that has no component of its own. */
export const buildPreviewComposedViewComponents = () =>
  buildComposedViewComponentRegistry([
    ...Object.values(composedViewComponents),
    ...PREVIEW_STUB_COMPONENTS.filter(
      (stub) => !Object.prototype.hasOwnProperty.call(composedViewComponents, stub.nodeType),
    ),
  ]);
