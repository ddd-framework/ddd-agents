import { Text } from "../../provider/components";
import { escapePlainTextForText } from "../../provider/environment";
/**
 * The component library's `Text` for a string the MODEL wrote (`model-text.ts`): `translate={false}` and the string
 * escaped, so `<b onmouseover="…">` or `<link href="…">` in a model's label renders as those characters. Every other
 * `Text` prop passes through.
 */
export const ModelText = (props) => {
    const { children, ...textProps } = props;
    return (<Text {...textProps} translate={false}>
      {escapePlainTextForText(children)}
    </Text>);
};
