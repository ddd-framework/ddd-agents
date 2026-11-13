---
# Purpose template. Copy to config/app/AI/Purposes/<Domain>/<Feature>.md; the dotted path is the purpose name.
# Register the name in the host's purpose registry (PurposeNameRegistry.php) and write the class (PurposeClass.php).
# Delete the keys you do not need and these comment lines. Identifiers stay plain; free text uses the folded
# block form (>-), which survives quotes, apostrophes, colons and hash characters.
# Never put purposeClass in double quotes: its backslashes become YAML escapes.
# placeholder {{PURPOSE_CLASS_FQCN}} sample: App\Domain\Demo\Entities\AI\Conversations\Purposes\DemoNotesReviewPurpose
# placeholder {{DESCRIPTION}} sample: Walks the owner through the notes once and tidies them up.
# placeholder {{SKILL_NAME}} sample: Demo.Notes
# placeholder {{KICKOFF_SIGNAL}} sample: Begin the notes review. Greet the owner and read the notes first.
# placeholder {{FIRST_ITEM}} sample: Read the owner's notes
# placeholder {{READ_TOOL}} sample: getDemoNotes
# placeholder {{SECOND_ITEM}} sample: Keep the first note the owner dictates
# placeholder {{CRITERION_KEY}} sample: notes.firstNoteKept
# placeholder {{STEP_KEY}} sample: notes
# placeholder {{STEP_LABEL}} sample: Your notes
# placeholder {{TITLE}} sample: Notes review
purposeClass: {{PURPOSE_CLASS_FQCN}}
description: >-
  {{DESCRIPTION}}
preloadSkillNames: [{{SKILL_NAME}}]
pinPreloadedSkills: true
kickoffSignalText: >-
  {{KICKOFF_SIGNAL}}
customerProgressSteps:
  - key: {{STEP_KEY}}
    customerLabel: >-
      {{STEP_LABEL}}
seedTaskPlanItems:
  - text: >-
      {{FIRST_ITEM}}
    doneRequiresToolCall: {{READ_TOOL}}
    customerProgressStepKey: {{STEP_KEY}}
  - text: >-
      {{SECOND_ITEM}}
    doneCriterion: {{CRITERION_KEY}}
    customerProgressStepKey: {{STEP_KEY}}
# Optional: allowedLoadSkillNames, requiredFeatureFlags, blockedToolNames, blockedToolsRefusalText,
# maxStepsPerTurn, completedConversationStaysSingleton, startableViaApi, inheritsFrom.
---
# {{TITLE}}

Work through the plan with the owner. Read before you write; keep each note in the owner's words.
