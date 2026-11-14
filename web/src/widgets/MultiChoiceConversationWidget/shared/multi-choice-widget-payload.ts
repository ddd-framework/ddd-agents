export interface MultiChoiceOption {
  label: string;
  sublabel?: string;
  value: string;
  selected?: boolean;
}

export interface MultiChoicePayload {
  title?: string;
  subtitle?: string;
  options: MultiChoiceOption[];
  selectAllLabel?: string;
  confirmLabelTemplate?: string;
  confirmAction?: string;
}

/** The `MultiChoice` fence payload: an object with an `options` list; anything else renders nothing. */
export const parseMultiChoicePayload = (raw: string): MultiChoicePayload | null => {
  try {
    const parsed = JSON.parse(raw);
    if (!parsed || !Array.isArray(parsed.options)) return null;
    return parsed;
  } catch {
    return null;
  }
};
