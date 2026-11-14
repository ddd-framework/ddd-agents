import { Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import type { ReactNode } from "react";
import type { ComposedSizeClass } from "../../../renderer/widget-tree/composed-node-schema";
import { WidgetSurface } from "../../../widgets/_shared/modern";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import styles from "./RichNodeFrame.module.scss";

export interface RichNodeFrameProps {
  /** The room the node has: at the root (`full`) the node is the widget and sits on its own elevated surface. */
  sizeClass: ComposedSizeClass;
  /** The model-written heading (business content, never translated). */
  title?: string;
  /** The model-written line under the heading. */
  caption?: string;
  /** A short technical label on the right of the header ("LaTeX", "PHP"), shown as written. */
  badge?: ReactNode;
  /** The header's controls (copy, wrap), right-aligned. */
  actions?: ReactNode;
  /** A node that is a tile wherever it sits (a code block); otherwise a nested node renders without a surface. */
  isTileWhenNested?: boolean;
  /** The muted line under the content (Math's example line), divided by a hairline. */
  footer?: ReactNode;
  children: ReactNode;
  testId?: string;
}

/**
 * The frame every rich kit node shares: on the chat (the tree's root) an elevated card with the
 * heading, the caption and the header controls; nested in another node, the same parts without a surface of their own
 * (the parent is the card) — a code block keeps a tile there, flat inside a surface and elevated outside one. The title is `textIntro` bold at the root and
 * `text` bold inside a parent.
 */
export const RichNodeFrame = (props: RichNodeFrameProps) => {
  const { sizeClass, title, caption, badge, actions, isTileWhenNested = false, footer, children, testId } = props;
  const isRoot = sizeClass === "full";
  const hasHeader = Boolean(title) || badge !== undefined || actions !== undefined;
  const content = (
    <>
      {hasHeader ? (
        <div className={styles.header}>
          <div className={styles.heading}>
            {title ? (
              <Text
                role={isRoot ? "intro" : "body"}
                weight="bold"
                translate={false}
                className={styles.wrappingText}
              >
                {escapePlainTextForText(title)}
              </Text>
            ) : null}
            {caption ? (
              <Text
                role="help"
                color="var(--agents-fg-light)"
                translate={false}
                className={styles.wrappingText}
              >
                {escapePlainTextForText(caption)}
              </Text>
            ) : null}
          </div>
          {badge !== undefined || actions !== undefined ? (
            <div className={styles.headerEnd}>
              {badge}
              {actions}
            </div>
          ) : null}
        </div>
      ) : null}
      {children}
      {footer ? <div className={styles.footer}>{footer}</div> : null}
    </>
  );
  if (isRoot)
    return (
      <WidgetSurface level="elevated" testId={testId}>
        {content}
      </WidgetSurface>
    );
  // A code block keeps a tile of its own: elevated when its parents draw no surface (a Stack on the chat), flat inside
  // a surface — the stylesheet reads WidgetSurface's `data-surface-level` on an ancestor.
  if (isTileWhenNested)
    return (
      <div className={styles.tile} data-testid={testId}>
        {content}
      </div>
    );
  return (
    <div className={classNames(styles.nested, sizeClass === "compact" && styles.compact)} data-testid={testId}>
      {content}
    </div>
  );
};

export interface RichNodeContentSkeletonProps {
  /** The height of the content block it stands for, in CSS pixels. */
  height: number;
  testId?: string;
}

/** The loading block of a rich node: the content's own shape (the heading stays real text), marked busy. */
export const RichNodeContentSkeleton = (props: RichNodeContentSkeletonProps) => {
  const { height, testId } = props;
  return (
    <div className={styles.skeleton} aria-busy="true" data-testid={testId}>
      <SkeletonLine width="100%" height={height} radius={12} />
    </div>
  );
};

export interface RichNodeBadgeProps {
  /** A technical name shown as written ("LaTeX", "PHP", "Mermaid"). */
  children: string;
}

/** The quiet label in a rich node's header that names the notation (ChatGPT's "LaTeX" badge). */
export const RichNodeBadge = (props: RichNodeBadgeProps) => {
  const { children } = props;
  return (
    <span className={styles.badge}>
      <Text role="caption" weight="medium" color="var(--agents-fg-light)" translate={false}>
        {escapePlainTextForText(children)}
      </Text>
    </span>
  );
};
