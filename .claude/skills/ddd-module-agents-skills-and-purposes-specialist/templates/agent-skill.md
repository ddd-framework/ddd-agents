---
# Agent skill template. Copy to config/app/AI/Skills/<Domain>/<Feature>.md; the dotted path is the skill name.
# Bind the name in config/app/AI/skillPackages.php. Delete the keys you do not need and these comment lines.
# placeholder {{DECISION_CRITERIA}} sample: The owner asks to keep a note, or asks what the notes say.
# placeholder {{SHORT_DESCRIPTION}} sample: keeps the owner's notes and reads them back
# placeholder {{DESCRIPTION}} sample: Keep a note for the owner with addDemoNote, read the notes back with getDemoNotes.
# placeholder {{TITLE}} sample: The owner's notes
# placeholder {{WRITE_TOOL}} sample: addDemoNote
# placeholder {{READ_TOOL}} sample: getDemoNotes
decisionCriteria: >-
  {{DECISION_CRITERIA}}
shortDescription: >-
  {{SHORT_DESCRIPTION}}
description: >-
  {{DESCRIPTION}}
# Gates (all optional; a host can also set them in config/app/AI/skillRequirements.php):
# requiredFeatureFlags: []
# anyOfRequiredFeatureFlags: []
# requiredAccountRights: []
# Hierarchy and binding (all optional):
# isSubSkill: false
# subSkillNames: []
# onlyUnderPurposeNames: []
# notUnderPurposeNames: []
---
# {{TITLE}}

- When the owner asks you to note, remember or write down something, call `{{WRITE_TOOL}}` with the owner's words.
- When the owner asks what the notes say, call `{{READ_TOOL}}` and list them.
- Confirm in one short sentence; never invent a note.
