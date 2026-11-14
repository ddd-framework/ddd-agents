import { Icon, Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { useId, useMemo, useState, type CSSProperties } from "react";
import { translateText } from "../../../provider/environment";
import { HeroValue, WidgetSurface } from "../../_shared/modern";
import {
  MAX_CURRENCY_CONVERTER_AMOUNT,
  type CurrencyConverterPayload,
} from "../shared/currency-converter-widget-payload";
import {
  formatCurrencyAmount,
  formatEditableAmount,
  formatExactNumber,
  formatRateDate,
  parseTypedAmount,
  readCurrencySymbol,
  toCurrencyLocale,
} from "../shared/currency-display";
import { CurrencyLabel } from "./CurrencyLabel/CurrencyLabel";
import styles from "./CurrencyConverterWidget.module.scss";

export interface CurrencyConverterWidgetProps {
  conversion: CurrencyConverterPayload;
  /** The app's UI language (`de`, `en_US`); numbers, dates and currency names follow it. */
  languageCode?: string;
  /** The e-mail capture: the amount is plain text and there is no swap button. */
  isStatic?: boolean;
  testId?: string;
}

/** The pair as shown right now: swapping turns the rate into its inverse, so nothing is fetched. */
const buildShownPair = (conversion: CurrencyConverterPayload, isSwapped: boolean) =>
  isSwapped
    ? { fromCurrency: conversion.toCurrency, toCurrency: conversion.fromCurrency, rate: 1 / conversion.rate }
    : { fromCurrency: conversion.fromCurrency, toCurrency: conversion.toCurrency, rate: conversion.rate };

/**
 * The currency converter (ChatGPT's `CurrencyConverterWidgetWithSource`): the title above one
 * elevated card with two rows — the amount in the first currency (editable) and its value in the second, a round swap
 * button on the hairline between them — and a footer with the rate and the day it is of. Changing the amount and
 * swapping the currencies recompute in the browser from the one delivered rate; the rate is dated, so the widget says
 * "Rate of <day>" and never "live". The currencies are the delivered pair only — there is no picker.
 */
export const CurrencyConverterWidget = (props: CurrencyConverterWidgetProps) => {
  const { conversion, languageCode, isStatic = false, testId = "currency-converter-widget" } = props;
  const locale = useMemo(() => toCurrencyLocale(languageCode), [languageCode]);
  const fromNameId = useId();
  const [isSwapped, setIsSwapped] = useState(false);
  const [typedAmount, setTypedAmount] = useState(() => formatEditableAmount(conversion.amount, locale));
  const { fromCurrency, toCurrency, rate } = buildShownPair(conversion, isSwapped);

  const typedAmountNumber = parseTypedAmount(typedAmount, locale);
  const amount =
    typedAmountNumber !== undefined && typedAmountNumber >= 0 && typedAmountNumber <= MAX_CURRENCY_CONVERTER_AMOUNT
      ? typedAmountNumber
      : undefined;
  const isInvalid = typedAmount.trim() !== "" && amount === undefined;
  const convertedAmount = amount === undefined ? undefined : amount * rate;
  const fromSymbol = readCurrencySymbol(fromCurrency, locale);
  const toSymbol = readCurrencySymbol(toCurrency, locale);
  const rateDateText = conversion.rateDate ? formatRateDate(conversion.rateDate, locale) : undefined;
  // The field is as wide as what is typed (plus the caret), so the symbol sits right behind the number.
  const fieldWidth: CSSProperties = { width: `${Math.max(typedAmount.length, 1) + 0.5}ch` };

  return (
    <section className={styles.widget} data-testid={testId}>
      <div className={styles.title}>
        <Text role="intro" weight="bold" testId={`${testId}-title`}>
          {"Currency converter"}
        </Text>
      </div>
      <WidgetSurface>
        <div className={styles.row}>
          <CurrencyLabel
            currencyCode={fromCurrency}
            locale={locale}
            nameId={fromNameId}
            testId={`${testId}-from-currency`}
          />
          {isStatic ? (
            <div className={styles.amount}>
              <Text role="title" translate={false} testId={`${testId}-amount-static`}>
                {amount === undefined ? "–" : formatCurrencyAmount(amount, fromCurrency, locale)}
              </Text>
              <Text role="intro" color="var(--agents-fg-light)" translate={false}>
                {fromSymbol}
              </Text>
            </div>
          ) : (
            <div
              className={classNames(styles.amount, styles.field, isInvalid && styles.fieldInvalid)}
              data-testid="currency-converter-amount"
            >
              {/* A native input: the design is a borderless field as wide as its number, which the component library's outlined
                  labelled Input cannot express. Named by the currency name beside it. */}
              <input
                className={styles.input}
                data-testid={`${testId}-input-amount`}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                aria-labelledby={fromNameId}
                aria-invalid={isInvalid || undefined}
                value={typedAmount}
                style={fieldWidth}
                onChange={(event) => setTypedAmount(event.target.value.slice(0, 18))}
                onBlur={() => amount !== undefined && setTypedAmount(formatEditableAmount(amount, locale))}
              />
              <Text role="intro" color="var(--agents-fg-light)" translate={false}>
                {fromSymbol}
              </Text>
            </div>
          )}
        </div>

        <div className={styles.divider}>
          {isStatic ? null : (
            <button
              type="button"
              className={styles.swap}
              aria-label={translateText("Swap currencies")}
              data-testid="currency-converter-swap"
              onClick={() => setIsSwapped((wasSwapped) => !wasSwapped)}
            >
              <Icon name="sorting" />
            </button>
          )}
        </div>

        <div className={styles.row}>
          <CurrencyLabel currencyCode={toCurrency} locale={locale} testId={`${testId}-to-currency`} />
          <div className={classNames(styles.amount, styles.result)} data-testid="currency-converter-result">
            {/* HeroValue shrinks to the width of its hero row's parent: the slot beside the symbol, never wider. */}
            <span className={styles.heroSlot}>
              <span className={styles.heroRow} data-hero-row="">
                <HeroValue>
                  {convertedAmount === undefined ? "–" : formatCurrencyAmount(convertedAmount, toCurrency, locale)}
                </HeroValue>
              </span>
            </span>
            <Text role="intro" color="var(--agents-fg-light)" translate={false}>
              {toSymbol}
            </Text>
          </div>
        </div>

        <div className={styles.footer}>
          <Text
            role="caption"
            weight="medium"
            translate={false}
            testId={`${testId}-rate`}
          >
            {`1 ${fromCurrency} = ${formatExactNumber(rate, locale)} ${toCurrency}`}
          </Text>
          {rateDateText ? (
            <Text
              role="caption"
              color="var(--agents-fg-light)"
              replacements={{ rateDate: rateDateText }}
              testId={`${testId}-rate-date`}
            >
              {"Rate of %rateDate%"}
            </Text>
          ) : null}
        </div>
        {isInvalid ? (
          <Text
            role="caption"
            color="var(--agents-danger-500)"
            testId={`${testId}-amount-hint`}
          >
            {"Enter an amount, such as 250 or 1,250.75"}
          </Text>
        ) : null}
      </WidgetSurface>
    </section>
  );
};
