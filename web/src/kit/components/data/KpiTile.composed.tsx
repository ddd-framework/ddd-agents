import { TrendDelta } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import styles from "./KpiTile.module.scss";
import { WidgetKpiValue, WidgetSurface, WidgetText } from "../../../widgets/_shared/modern";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import { useLayoutNesting } from "../layout/layout-nesting-context";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { DataFootnote } from "./DataFootnote/DataFootnote";
import { TONE_GRAPHIC_COLOR, TONE_TEXT_CLASS } from "./data-tones";
import {
  formatDataValue,
  readDataValueFormat,
  readDataValueTone,
  readFiniteNumber,
  readText,
} from "./data-value-format";
import { KpiMeter } from "./KpiMeter/KpiMeter";
import { KpiToneAccent } from "./KpiToneAccent/KpiToneAccent";
import { readKpiValueParts } from "./kpi-value-parts";
import { useNumberLocale } from "./use-number-locale";
import { useReportDataNodeStatus } from "./use-report-data-node-status";

// A neutral score fills in the call-to-action colour; a judged one in its tone's graphic colour.
const readMeterColor = (tone: keyof typeof TONE_GRAPHIC_COLOR): string =>
  tone === "neutral" ? "var(--agents-fg-cta)" : TONE_GRAPHIC_COLOR[tone];

/**
 * The `KpiTile` node: label → hero value → unit and change → detail → source,
 * the design's anatomy of a number tile, built on `WidgetKpiValue` (the ONE hero scale).
 * The change is the component library's `TrendDelta`: its arrow colour says whether the change is good (`higherIsBetter`), not its
 * direction; the number carries its sign ("+12.4 %"). On the chat (no `Surface` around it, the nesting context of the layout nodes) the tile is an elevated surface; inside a
 * `Surface` it is a flat tile on the nested background, so a grid of tiles in a card has two background levels, never
 * three. Outside the root slot it takes the compact padding. A value that is still
 * loading is the hero's skeleton; a value that resolved to nothing shows a dash and reports `empty`.
 */
const KpiTileNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, runtime, isPending, sizeClass, testId } = props;
  const locale = useNumberLocale();
  const { isInsideSurface } = useLayoutNesting();
  const label = readText(nodeProps.label) ?? "";
  const format = readDataValueFormat(nodeProps.format);
  const currency = readText(nodeProps.currency);
  const decimals = readFiniteNumber(nodeProps.decimals);
  const formattedValue = formatDataValue(nodeProps.value, {
    format,
    currency,
    decimals,
    signed: nodeProps.signed === true,
    locale,
  });
  const hasValue = formattedValue !== "";
  const delta = readFiniteNumber(nodeProps.delta);
  const deltaFormat = readDataValueFormat(nodeProps.deltaFormat);
  const deltaLabel = readText(nodeProps.deltaLabel);
  const higherIsBetter = nodeProps.higherIsBetter !== false;
  const tone = readDataValueTone(nodeProps.tone);
  const unit = readText(nodeProps.unit);
  const detail = readText(nodeProps.detail);
  const valueParts = readKpiValueParts(nodeProps.value, formattedValue, format);
  const { ratio, secondary } = valueParts;
  useReportDataNodeStatus(
    runtime,
    isPending ? "pending" : hasValue ? "mounted" : "empty",
    !isPending && !hasValue ? "NO_DATA" : undefined,
    !isPending && !hasValue ? `the KpiTile "${label}" has no value` : undefined,
  );
  const deltaElement =
    delta === undefined ? undefined : (
      <span className={styles.delta} data-testid={`${testId}-delta`}>
        <TrendDelta
          current={delta}
          previous={0}
          higherIsBetter={higherIsBetter}
          // TrendDelta hands over the magnitude only; the change keeps its sign ("+6", "+5 %", "-0.49").
          format={(magnitude) =>
            formatDataValue(delta < 0 ? -magnitude : magnitude, { format: deltaFormat, currency, locale, signed: true })
          }
        />
        {deltaLabel ? (
          <WidgetText variant="caption" tone="light" translate={false} testId={`${testId}-delta-label`}>
            {escapePlainTextForText(deltaLabel)}
          </WidgetText>
        ) : null}
      </span>
    );
  return (
    <WidgetSurface
      level={isInsideSurface ? "flat" : "elevated"}
      compact={sizeClass !== "full"}
      className={styles.tile}
      testId={testId}
    >
      {isPending ? (
        // The primitive's own loading skeleton animates its style every frame — a capture never settles on it.
        <div className={styles.loading} aria-busy="true" data-testid={`${testId}-skeleton`}>
          <WidgetText variant="caption" tone="light" translate={false} testId={`${testId}-label`}>
            {escapePlainTextForText(label)}
          </WidgetText>
          <SkeletonLine width="60%" height={40} radius={8} />
        </div>
      ) : (
        <WidgetKpiValue
          testId={testId}
          valuePresentation={valueParts.presentation}
          label={escapePlainTextForText(label)}
          accent={
            tone === "neutral" || tone === "muted" ? undefined : (
              <KpiToneAccent tone={tone} testId={`${testId}-accent`} />
            )
          }
          value={
            <span className={TONE_TEXT_CLASS[tone]} data-testid={`${testId}-value`}>
              <WidgetText translate={false}>{hasValue ? escapePlainTextForText(valueParts.lead) : "—"}</WidgetText>
              {valueParts.denominator ? (
                <span className={styles.denominator}>
                  <WidgetText translate={false}>{escapePlainTextForText(valueParts.denominator)}</WidgetText>
                </span>
              ) : null}
            </span>
          }
          meter={
            ratio ? (
              <KpiMeter value={ratio.value} max={ratio.max} color={readMeterColor(tone)} testId={`${testId}-meter`} />
            ) : undefined
          }
          unit={
            secondary ? (
              <span className={styles.unitParts}>
                <WidgetText variant="value" translate={false} inline testId={`${testId}-value-secondary`}>
                  {escapePlainTextForText(secondary)}
                </WidgetText>
                {unit ? <WidgetText translate={false}>{escapePlainTextForText(unit)}</WidgetText> : null}
              </span>
            ) : unit ? (
              escapePlainTextForText(unit)
            ) : undefined
          }
          delta={deltaElement}
        />
      )}
      {detail ? (
        <WidgetText variant="caption" tone="light" translate={false} testId={`${testId}-detail`}>
          {escapePlainTextForText(detail)}
        </WidgetText>
      ) : null}
      <DataFootnote
        source={nodeProps.source}
        isEmailRender={runtime.isEmailRender}
        appearance="quiet"
        testId={`${testId}-footnote`}
      />
    </WidgetSurface>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "KpiTile", component: KpiTileNode };
