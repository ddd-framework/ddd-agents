import { classNames } from "../../../provider/class-names";
import { readBooleanProp, readEnumProp } from "./layout-props";
import styles from "./Spacer.module.scss";
const SIZES = ["small", "medium", "large"];
/** `Spacer` (`SpacerComposedViewNode.php`): a fixed step of room, or all the free room (`grow`). */
const Spacer = (props) => {
    const { props: nodeProps } = props;
    const size = readEnumProp(nodeProps.size, SIZES, "medium");
    const isGrowing = readBooleanProp(nodeProps.grow, false);
    return <div aria-hidden="true" className={classNames(styles.spacer, styles[size], isGrowing && styles.grow)}/>;
};
export const composedViewComponent = { nodeType: "Spacer", component: Spacer };
