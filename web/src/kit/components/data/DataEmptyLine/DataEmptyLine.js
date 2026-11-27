import { Text } from "../../../../provider/components";
import { escapePlainTextForText } from "../../../../provider/environment";
import { readText } from "../data-value-format";
/** The one muted line a data node shows when it has nothing to show (empty data). */
export const DataEmptyLine = (props) => {
    const { emptyText, testId } = props;
    const modelText = readText(emptyText);
    return modelText ? (<Text role="help" color="var(--agents-fg-light)" translate={false} testId={testId}>
      {escapePlainTextForText(modelText)}
    </Text>) : (<Text role="help" color="var(--agents-fg-light)" testId={testId}>
      {"No data to show."}
    </Text>);
};
