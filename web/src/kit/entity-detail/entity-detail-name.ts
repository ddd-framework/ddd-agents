import { createContext, useContext, useEffect } from "react";

/**
 * The name of the entity the `entityDetail` canvas shows: a detail body knows it only once it hydrated,
 * the shell needs it for the "Ask about this" chip — ChatGPT's follow-up chip names the business, a chip without it
 * says "About this entry". A body reports the name with {@link useReportEntityDetailName}; outside the shell (a story,
 * a spec of the body alone) the report goes nowhere.
 */
export const EntityDetailNameReportContext = createContext<(entityName: string | undefined) => void>(() => undefined);

/** Reports the shown entity's name (the hydrated business, keyword or review title) to the `entityDetail` shell. */
export const useReportEntityDetailName = (entityName: string | undefined): void => {
  const reportEntityName = useContext(EntityDetailNameReportContext);
  const trimmedName = entityName?.trim() || undefined;
  useEffect(() => {
    reportEntityName(trimmedName);
  }, [reportEntityName, trimmedName]);
};
