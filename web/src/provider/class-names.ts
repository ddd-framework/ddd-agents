type ClassNameValue = string | undefined | null | false | Readonly<Record<string, boolean | undefined | null>>;

/** The class names one argument contributes: a set string, or every key of an object whose value is truthy. */
const readClassNamesOfValue = (value: ClassNameValue): string[] => {
  if (typeof value === "string") return value.length > 0 ? [value] : [];
  if (!value) return [];
  return Object.keys(value).filter((className) => className.length > 0 && Boolean(value[className]));
};

/**
 * Joins the class names that are set, skipping empty and falsy values; an object adds each key whose value is truthy
 * (`classNames(styles.card, { [styles.selected]: isSelected })`), in argument order, as the `classnames` package does.
 */
export const classNames = (...args: ClassNameValue[]): string => args.flatMap(readClassNamesOfValue).join(" ");
