import { classNames } from "../../../provider/class-names";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readBooleanProp, readEnumProp } from "./layout-props";
import styles from "./Spacer.module.scss";

const SIZES = ["small", "medium", "large"] as const;

/** `Spacer` (`SpacerComposedViewNode.php`): a fixed step of room, or all the free room (`grow`). */
const Spacer = (props: ComposedViewComponentProps) => {
  const { props: nodeProps } = props;
  const size = readEnumProp(nodeProps.size, SIZES, "medium");
  const isGrowing = readBooleanProp(nodeProps.grow, false);
  return <div aria-hidden="true" className={classNames(styles.spacer, styles[size], isGrowing && styles.grow)} />;
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Spacer", component: Spacer };
