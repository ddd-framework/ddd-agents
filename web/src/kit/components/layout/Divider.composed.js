import { readEnumProp } from "./layout-props";
import styles from "./Divider.module.scss";
const ORIENTATIONS = ["horizontal", "vertical"];
/** `Divider` (`DividerComposedViewNode.php`): a hairline, horizontal in a column, vertical in a row. */
const Divider = (props) => {
    const { props: nodeProps } = props;
    const orientation = readEnumProp(nodeProps.orientation, ORIENTATIONS, "horizontal");
    return <div role="separator" aria-orientation={orientation} className={styles[orientation]}/>;
};
export const composedViewComponent = { nodeType: "Divider", component: Divider };
