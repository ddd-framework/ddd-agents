import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readEnumProp } from "./layout-props";
import styles from "./Divider.module.scss";

const ORIENTATIONS = ["horizontal", "vertical"] as const;

/** `Divider` (`DividerComposedViewNode.php`): a hairline, horizontal in a column, vertical in a row. */
const Divider = (props: ComposedViewComponentProps) => {
  const { props: nodeProps } = props;
  const orientation = readEnumProp(nodeProps.orientation, ORIENTATIONS, "horizontal");
  return <div role="separator" aria-orientation={orientation} className={styles[orientation]} />;
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Divider", component: Divider };
