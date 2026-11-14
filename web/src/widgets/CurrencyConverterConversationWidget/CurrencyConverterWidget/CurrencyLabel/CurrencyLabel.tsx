import { Text } from "../../../../provider/components";
import { readCurrencyFlag, readCurrencyName } from "../../shared/currency-display";
import styles from "./CurrencyLabel.module.scss";

export interface CurrencyLabelProps {
  currencyCode: string;
  locale: string;
  /** Id of the name line, so the amount field beside it can be named by it. */
  nameId?: string;
  testId?: string;
}

/** One currency of the converter: a round flag badge, the ISO code and the currency's name in the UI language. */
export const CurrencyLabel = (props: CurrencyLabelProps) => {
  const { currencyCode, locale, nameId, testId } = props;
  return (
    <div className={styles.currency} data-testid={testId}>
      <span aria-hidden="true" className={styles.flag}>
        {readCurrencyFlag(currencyCode)}
      </span>
      <div className={styles.text}>
        <Text role="help" weight="bold" translate={false}>
          {currencyCode}
        </Text>
        <span id={nameId}>
          <Text role="caption" color="var(--agents-fg-light)" ellipsis translate={false}>
            {readCurrencyName(currencyCode, locale)}
          </Text>
        </span>
      </div>
    </div>
  );
};
