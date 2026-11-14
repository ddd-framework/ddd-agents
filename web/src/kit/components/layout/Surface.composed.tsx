import { Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { useEffect, useMemo } from "react";
import { WidgetSurface } from "../../../widgets/_shared/modern";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readEnumProp, readTextProp } from "./layout-props";
import { LayoutNestingContext, useLayoutNesting } from "./layout-nesting-context";
import styles from "./Surface.module.scss";

const DENSITIES = ["regular", "compact"] as const;

/**
 * `Surface` (`SurfaceComposedViewNode.php`; ChatGPT's `Card` — named Surface, the project's noun is
 * never "card"): a titled group of nodes. The outermost surface is raised on the chat (`WidgetSurface` elevated); a
 * surface inside another one is a flat tile, so a composition never stacks three background levels. The title is the
 * widget title (`textIntro` bold) on the outer surface and a tile title (`text` bold) inside; the subtitle is one muted
 * line. Slots: `media` flush at the top, `header` at the end of the title row, the body, `footer` at the foot. A
 * surface with nothing to show reports `empty`.
 */
const Surface = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, runtime, testId } = props;
  const nesting = useLayoutNesting();
  const title = readTextProp(nodeProps.title);
  const subtitle = readTextProp(nodeProps.subtitle);
  const isCompact = readEnumProp(nodeProps.density, DENSITIES, "regular") === "compact";
  const mediaNodes = slots.media ?? [];
  const headerNodes = slots.header ?? [];
  const bodyNodes = slots.children ?? [];
  const footerNodes = slots.footer ?? [];
  const hasContent =
    title !== undefined ||
    subtitle !== undefined ||
    mediaNodes.length + headerNodes.length + bodyNodes.length + footerNodes.length > 0;
  const { reportNodeStatus } = runtime;
  const innerNesting = useMemo(() => ({ ...nesting, isInsideSurface: true }), [nesting]);

  useEffect(() => {
    if (hasContent) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Surface has no title and no content");
  }, [hasContent, reportNodeStatus]);

  if (!hasContent) return null;
  const isOutermost = !nesting.isInsideSurface;
  const hasHeader = title !== undefined || subtitle !== undefined || headerNodes.length > 0;
  return (
    <WidgetSurface
      level={isOutermost ? "elevated" : "flat"}
      compact={isCompact}
      testId={testId}
      className={classNames(styles.surface, isCompact && styles.compact)}
    >
      <LayoutNestingContext.Provider value={innerNesting}>
        {mediaNodes.length > 0 ? (
          <div className={classNames(styles.media, isCompact && styles.compactMedia)}>{mediaNodes}</div>
        ) : null}
        {hasHeader ? (
          <div className={styles.header}>
            {title !== undefined || subtitle !== undefined ? (
              <div className={styles.titles}>
                {title !== undefined ? (
                  <Text
                    role={isOutermost ? "intro" : "body"}
                    weight="bold"
                    translate={false}
                  >
                    {escapePlainTextForText(title)}
                  </Text>
                ) : null}
                {subtitle !== undefined ? (
                  <Text role="help" color="var(--agents-fg-light)" translate={false}>
                    {escapePlainTextForText(subtitle)}
                  </Text>
                ) : null}
              </div>
            ) : null}
            {headerNodes.length > 0 ? <div className={styles.headerEnd}>{headerNodes}</div> : null}
          </div>
        ) : null}
        {bodyNodes.length > 0 ? <div className={styles.body}>{bodyNodes}</div> : null}
        {footerNodes.length > 0 ? <div className={styles.footer}>{footerNodes}</div> : null}
      </LayoutNestingContext.Provider>
    </WidgetSurface>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Surface", component: Surface };
