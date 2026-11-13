# Asking the owner a closed question

**Reader:** host developer and package maintainer

Contents
- What askOwner is
- The round trip
- The argument check
- The forced answer step without tools
- The catalogue: questions the platform understands
- Reading the pick back in a tool
- Where it does nothing

## What askOwner is

`askOwner` (`AskOwnerTool`) is a meta tool of the loop — answered by the loop, never by an MCP package — for a CLOSED
question: a choice, a yes or no, a confirmation, an approval. The model writes the question and 2–4 short answer
options; the platform renders them as a widget under the model's final text, and the owner picks or types. Open
questions (a name, a number, an address, free wording) are asked in prose. It is offered in every purpose, and
nothing refuses a closed question asked in prose.

## The round trip

| Step | Where | What |
|---|---|---|
| call | `AgentToolCallDispatchService::dispatchToolCall()` → `AskOwnerTool::answerCall()` | the arguments are checked; the question is RECORDED on the call row; nothing renders yet |
| answer | `RESULT_RECORDED` (or the variant naming the widget a read of this turn dictates) | tells the model: your final text carries what is new, never the question again; end the turn; follow through on any option you offer |
| turn end | `AIConversationTurnSignalsService::attachOwnerQuestionWidget()` | writes the widget as a tool row right under the final text: the question, then a `QuickReplies` block (single choice) or a `MultiChoice` block (several; the confirm posts the labels joined by `MULTI_PICK_SEPARATOR`); the block carries the question, the catalogue key and the meanings as extra fields the widgets ignore |
| pick | the owner's next text | a click arrives as an owner text equal to the label(s); a typed answer arrives as prose |

The widget is not written under an empty final text, not when the final text already carries an owner-question block
the model wrote itself, and not under a widget that ends the purpose. A failure to write it is swallowed — the
question stands in the text.

## The argument check

`AskOwnerTool::checkArguments()` returns the normalized `AskOwnerQuestion` or the feedback, and never refuses on
wording:

- `question` is required, at most `MAX_QUESTION_CHARS` (300) — one question; a draft belongs in the final text.
- `options`: `MIN_OPTIONS`–`MAX_OPTIONS` (2–4), each with a non-empty, unique `label` of at most `MAX_LABEL_CHARS` (80)
  and an optional `description` (cut to `MAX_DESCRIPTION_CHARS`, 160). With `multiSelect` a label must not contain
  the separator.
- `questionKey` + `optionMeanings`: an unknown key is dropped (a free question). A known key whose meanings do not fit
  — wrong count, an unknown meaning, `multiSelect`, or the same meaning on every option — is NOT refused: the key is
  dropped, the question is recorded as a free one, and the result says why (`AskOwnerArgumentsCheck::$keyDropped`).
  A refusal sent models into loops of re-asks with other keys.
- A malformed call fails with the feedback and does not force the next step.

## The forced answer step without tools

This is part of the tool's contract; the loop only executes it. Every RECORDED call answers with
`AgentMetaToolCallAnswer::withToolLessAnswerStep()`, and so does a second call in the same turn
(`RESULT_ALREADY_ASKED_THIS_TURN` — one question per turn). The dispatch raises the loop's flag; the next model call
runs with `tool_choice` `none` (`AgentStepToolChoice`): the tools stay on the wire (the cached tool list never
changes), but the model must write its final text now. Without it a model re-asked with new wording instead of writing
its text, and the turn died. The loop skill (`ddd-module-agents-loop-specialist`) explains the step budget around it.

## The catalogue: questions the platform understands

`AskOwnerQuestionCatalogue` holds the questions whose pick the platform records itself:

| Key | Meanings | Read by |
|---|---|---|
| `draftApproval` (`QUESTION_KEY_DRAFT_APPROVAL`) | `approved`, `change` | a purpose's draft gate: the widget stands for the draft the final text presents |

- A host adds its own questions in a class at the SAME PATH that extends `AskOwnerQuestionCatalogue` and redeclares
  `QUESTIONS` (and optionally `QUESTION_KEYS_NOT_LISTED_IN_THE_TOOL_DESCRIPTION` — kept in the schema enum, left out of
  the description). Every reader asks the getters (`AskOwnerQuestionCatalogue::getQuestions()`, `AskOwnerQuestionCatalogue::getQuestionKeys()`, `AskOwnerQuestionCatalogue::getMeaningsOfQuestion()`,
  `AskOwnerQuestionCatalogue::buildQuestionListOfTheToolDescription()`), which resolve the host's class
  (`AskOwnerQuestionCatalogue::getCatalogueClassOfTheApplication()`; how the package finds the host's class:
  `ddd-module-agents-specialist`, override points).
- A `draftApproval` key under a final text shorter than the purpose's `DRAFT_APPROVAL_MIN_LINE_CHARS`
  (`AIConversationPurpose::isFreeQuestionUnderDraftApproval()`) is written as a free question: a nod under a line
  that shows no draft must not unlock the writes the draft gate guards. The result of a recorded `draftApproval` call
  carries `RESULT_DRAFT_APPROVAL_NOTE` (the whole draft stands in the final text).

## Reading the pick back in a tool

A tool of the host that applies the owner's answer to a catalogue question reads the widgets, never the raw messages:

```php
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;

// $conversation: the live conversation of the call — $agentToolCallProvenance->getConversationToWriteInto()
$shownConversationWidgets = $conversation->findShownWidgets();
$ownerQuestionPick = AskOwnerTool::readOwnerPickOnNewestKeyedQuestion(
    $shownConversationWidgets,
    AskOwnerQuestionCatalogue::QUESTION_KEY_DRAFT_APPROVAL
);
$draftIsApproved = $ownerQuestionPick !== null
    && in_array(AskOwnerQuestionCatalogue::MEANING_APPROVED, $ownerQuestionPick->meanings, true);
```

- `AskOwnerTool::findNewestShownOwnerQuestionWidget()`: the newest `QuickReplies` / `MultiChoice` widget with an option list (and
  the key, when one is asked).
- `AskOwnerTool::readOwnerPickOnShownWidget()` / `AskOwnerTool::readOwnerPickOnNewestKeyedQuestion()`: the pick (`OwnerQuestionPick`: labels,
  descriptions, the key and the meanings) when the FIRST owner text after the widget picks options; null when the
  owner typed something else or has not answered. Never an older widget's pick.
- `AskOwnerTool::isFirstOwnerTextAfterWidget()`: an owner action on ANOTHER widget between the question and a typed text means the
  text answers what came after — unless it picks one of the question's options.
- `AskOwnerTool::isNewestKeyedQuestionAwaitingOwner()`, `AskOwnerTool::isNewestKeyedQuestionAnsweredByOwnerText()`,
  `AskOwnerTool::findKeyedQuestionAnsweredByOwnerText()` answer the gate questions.

A key the host added to its catalogue is read the same way. The widget classes and the shown-widget view are the
widgets skill's (`ddd-module-agents-widgets-specialist`).

## Where it does nothing

| Situation | Answer |
|---|---|
| the unattended first turn of a scheduled run (`OwnerPresence::ABSENT_SCHEDULED_RUN`) | `RESULT_NOBODY_PRESENT`, failed: decide or name the open question in the report |
| a delegation child | recorded; the widget block is dictated into the child's result for the main conversation to show (`AskOwnerTool::buildRecordedResultInADelegationChild()`) |
| the owner on the phone line | `RESULT_RECORDED_ON_THE_PHONE_LINE`: no widget; the question and its options go into the spoken text |
