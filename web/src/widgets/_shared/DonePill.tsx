import { Icon, Text } from "../../provider/components";
import { useEffect, useRef } from "react";
import styles from "./DonePill.module.scss";

interface DonePillProps {
  children: string;
  /** False for a label the agent wrote at runtime — only the static copy goes through the translator. */
  translate?: boolean;
  /** `neutral` for an answer that declined or cancelled: no check. */
  status?: "success" | "neutral";
  testId?: string;
}

/**
 * What was done, where the answer buttons were: one pill naming the action the owner chose. It takes the focus the
 * button had when that button unmounts with it; mount it inside a live region that was already there.
 */
export const DonePill = (props: DonePillProps) => {
  const { children, translate = true, status = "success", testId } = props;
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (document.activeElement === document.body) ref.current?.focus();
  }, []);

  return (
    <div ref={ref} tabIndex={-1} className={styles.pill} data-testid={testId}>
      {status === "success" && (
        <Icon pixelSize={14} color="var(--agents-success-500)" name="check" />
      )}
      <Text role="help" weight="medium" color="var(--agents-fg-light)" translate={translate}>
        {children}
      </Text>
    </div>
  );
};
