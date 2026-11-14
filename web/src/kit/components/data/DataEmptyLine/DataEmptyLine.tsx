import { Text } from "../../../../provider/components";
import { escapePlainTextForText } from "../../../../provider/environment";
import { readText } from "../data-value-format";

export interface DataEmptyLineProps {
  /** The model's own empty line (`emptyText`); the kit's chrome sentence otherwise. */
  emptyText?: unknown;
  testId?: string;
}

/** The one muted line a data node shows when it has nothing to show (empty data). */
export const DataEmptyLine = (props: DataEmptyLineProps) => {
  const { emptyText, testId } = props;
  const modelText = readText(emptyText);
  return modelText ? (
    <Text role="help" color="var(--agents-fg-light)" translate={false} testId={testId}>
      {escapePlainTextForText(modelText)}
    </Text>
  ) : (
    <Text role="help" color="var(--agents-fg-light)" testId={testId}>
      {"No data to show."}
    </Text>
  );
};
