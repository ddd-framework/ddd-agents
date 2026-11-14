import { Text } from "../../../provider/components";
import type { ComponentProps } from "react";
import type { ConceptExplainerText } from "./concept-explainer-type";

export type ConceptExplainerTextViewProps = Omit<
  ComponentProps<typeof Text>,
  "children" | "translate" | "replacements" | "context"
> & {
  text: ConceptExplainerText;
};

/**
 * One translated text of an explainer: the key through the component library's `Text`, its `%placeholders%` from `replacements`, the
 * singular / plural value picked by `context`. Never model text — that goes through `ModelText`.
 */
export const ConceptExplainerTextView = (props: ConceptExplainerTextViewProps) => {
  const { text, ...textProps } = props;
  return (
    <Text {...textProps} replacements={text.replacements} context={text.context}>
      {text.key}
    </Text>
  );
};
