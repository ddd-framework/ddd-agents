import { Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { useEffect, useState } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { loadDomPurify } from "./rich-libraries";
import { sanitizeSvgMarkup } from "./sanitize-svg-markup";
import { RichNodeContentSkeleton, RichNodeFrame } from "./RichNodeFrame";
import styles from "./Svg.module.scss";

type SvgRendering =
  | { status: "loading" }
  | { status: "empty" }
  | { status: "rendered"; svgMarkup: string; removedPartNames: string[] }
  | { status: "failed"; detail: string; removedPartNames: string[] };

/**
 * The `Svg` node (`SvgComposedViewNode.php`): a vector graphic the model drew,
 * shown only after DOMPurify (loaded on first use) reduced it to safe SVG — no scripts, no event attributes, no
 * `foreignObject`, no `style` element, no link and no reference outside the graphic ({@link sanitizeSvgMarkup}). The
 * markup may be a computed text over the tree's state, so the drawing follows the inputs (the radar whose shape moves
 * with its sliders); each change is sanitised again. Removed parts are reported `failed` with their names while the
 * rest is drawn; markup with nothing safe left renders one muted line.
 */
const SvgNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const { reportNodeStatus } = runtime;
  const markup = typeof nodeProps.markup === "string" ? nodeProps.markup : "";
  const label = typeof nodeProps.label === "string" && nodeProps.label.trim() !== "" ? nodeProps.label : undefined;
  const title = typeof nodeProps.title === "string" ? nodeProps.title : undefined;
  const caption = typeof nodeProps.caption === "string" ? nodeProps.caption : undefined;
  const [rendering, setRendering] = useState<SvgRendering>({ status: "loading" });

  useEffect(() => {
    if (isPending) {
      setRendering({ status: "loading" });
      return;
    }
    if (markup.trim() === "") {
      setRendering({ status: "empty" });
      return;
    }
    let isCurrent = true;
    loadDomPurify()
      .then((domPurifyFactory) => {
        if (!isCurrent) return;
        const sanitizedSvg = sanitizeSvgMarkup(domPurifyFactory, markup, {
          keepStyleElement: false,
          label: label ?? title,
        });
        setRendering(
          sanitizedSvg.markup === null
            ? {
                status: "failed",
                detail: "the markup holds no single <svg> element with safe content",
                removedPartNames: sanitizedSvg.removedPartNames,
              }
            : { status: "rendered", svgMarkup: sanitizedSvg.markup, removedPartNames: sanitizedSvg.removedPartNames },
        );
      })
      .catch(() => {
        if (isCurrent)
          setRendering({ status: "failed", detail: "the SVG sanitiser could not be loaded", removedPartNames: [] });
      });
    return () => {
      isCurrent = false;
    };
  }, [markup, label, title, isPending]);

  useEffect(() => {
    if (rendering.status === "loading") reportNodeStatus("pending");
    if (rendering.status === "empty") reportNodeStatus("empty", "NO_DATA", "the SVG markup is empty");
    if (rendering.status === "failed") {
      const removedPartsDetail =
        rendering.removedPartNames.length > 0 ? ` (removed: ${rendering.removedPartNames.join(", ")})` : "";
      reportNodeStatus("failed", "RENDER_FAILED", `${rendering.detail}${removedPartsDetail}`);
    }
    if (rendering.status === "rendered") {
      if (rendering.removedPartNames.length === 0) reportNodeStatus("mounted");
      else {
        reportNodeStatus(
          "failed",
          "INVALID_PROP",
          `unsafe SVG parts were removed (${rendering.removedPartNames.join(", ")}) — the rest is drawn`,
        );
      }
    }
  }, [rendering, reportNodeStatus]);

  // No markup: the node renders nothing and reports `empty` (one muted line or nothing).
  if (rendering.status === "empty") return null;
  const graphic =
    rendering.status === "rendered" ? (
      <div
        className={classNames(styles.graphic, sizeClass === "compact" && styles.compactGraphic)}
        dangerouslySetInnerHTML={{ __html: rendering.svgMarkup }}
      />
    ) : rendering.status === "loading" ? (
      <RichNodeContentSkeleton height={sizeClass === "compact" ? 48 : 200} testId={`${testId}-skeleton`} />
    ) : (
      <Text role="help" color="var(--agents-fg-light)" testId={`${testId}-error`}>
        {"This graphic could not be shown."}
      </Text>
    );

  return (
    <RichNodeFrame sizeClass={sizeClass} title={title} caption={caption} testId={testId}>
      {graphic}
    </RichNodeFrame>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Svg", component: SvgNode };
