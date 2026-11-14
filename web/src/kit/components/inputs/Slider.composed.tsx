import { useEffect, useRef, useState } from "react";
import { RangeSlider } from "../../../provider/components";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ComposedInputField } from "./shared/ComposedInputField";
import {
  countDecimals,
  formatComposedNumber,
  isComposedInputUsable,
  readComposedNumber,
  readComposedText,
  snapToSliderStep,
} from "./shared/composed-input-values";
import { useComposedInputLanguage } from "./shared/use-composed-input-language";
import { useComposedInputValue } from "./shared/use-composed-input-value";
import styles from "./ComposedInputs.module.scss";

/** While dragging, the tree state is written at most this often (the state store caps inputs at 30 updates a second). */
const DRAG_STATE_WRITE_INTERVAL_MS = 60;

const readFiniteNumber = (resolvedValue: ExpressionValue | undefined): number | undefined =>
  typeof resolvedValue === "number" && Number.isFinite(resolvedValue) ? resolvedValue : undefined;

/**
 * `Slider` — a number between `min` and `max` (`SliderComposedViewNode.php`; the sliders of ChatGPT's
 * revenue and MRR simulators): the label left, the formatted value right ("35,000", "3.0 %", "70 €" — `unit` and
 * `decimals`, in the owner's language), the track below. Dragging writes the tree state about 16 times a second so
 * computed KPIs follow the thumb; letting go always writes the final value. An invalid range (`max` not above `min`)
 * renders nothing and is reported. Frozen on a replay and in the e-mail capture.
 */
const ComposedSlider = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const languageCode = useComposedInputLanguage();
  const [dragValue, setDragValue] = useState<number | undefined>(undefined);
  const lastDragWriteAtRef = useRef(0);
  const min = readComposedNumber(nodeProps.min, 0);
  const max = readComposedNumber(nodeProps.max, 100);
  const rawStep = readComposedNumber(nodeProps.step, 1);
  const step = rawStep > 0 ? rawStep : 1;
  const unit = readComposedText(nodeProps.unit);
  const decimals = readComposedNumber(nodeProps.decimals, countDecimals(step));
  const label = readComposedText(nodeProps.label);
  const showsValue = nodeProps.showValue !== false;
  const isRangeValid = max > min;
  const { value, changeValue } = useComposedInputValue<number>({
    node,
    runtime,
    resolvedValue: nodeProps.value,
    readValue: readFiniteNumber,
    fallbackValue: min,
  });
  const { reportNodeStatus } = runtime;
  useEffect(() => {
    if (isRangeValid) return;
    reportNodeStatus("failed", "INVALID_PROP", `Slider max (${max}) must be above min (${min}) — it is not shown`);
  }, [isRangeValid, min, max, reportNodeStatus]);
  if (!isRangeValid) return null;
  const shownValue = snapToSliderStep(dragValue ?? value, min, max, step);
  const moveThumb = (movedValue: number) => {
    if (!isComposedInputUsable(runtime)) return;
    const snappedValue = snapToSliderStep(movedValue, min, max, step);
    setDragValue(snappedValue);
    const now = Date.now();
    if (now - lastDragWriteAtRef.current < DRAG_STATE_WRITE_INTERVAL_MS) return;
    lastDragWriteAtRef.current = now;
    runtime.runEvent(node.props.onChange, snappedValue);
  };
  const releaseThumb = (releasedValue: number) => {
    setDragValue(undefined);
    changeValue(snapToSliderStep(releasedValue, min, max, step));
  };
  const isUsable = isComposedInputUsable(runtime);
  return (
    <ComposedInputField
      label={label}
      labelRowValue={
        showsValue && !isPending ? formatComposedNumber(shownValue, decimals, unit, languageCode) : undefined
      }
      isUsable={isUsable}
      isPending={isPending}
      pendingHeight={28}
      testId={testId}
    >
      <div className={styles.sliderTrack} aria-label={label} data-testid={testId}>
        <RangeSlider
          value={shownValue}
          min={min}
          max={max}
          step={step}
          onMove={moveThumb}
          onRelease={releaseThumb}
        />
      </div>
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Slider", component: ComposedSlider };
