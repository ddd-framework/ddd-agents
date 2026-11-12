---
decisionCriteria: "Only inside a scheduled run: read the reports the earlier runs of the same task wrote for the owner."
shortDescription: "reads the earlier reports of the scheduled task this run executes"
onlyUnderPurposeNames: ['Schedule.Run']
description: >-
  Inside a scheduled run only: read the reports the earlier runs of the same task wrote for the owner with
  getPreviousTaskRunReports — to keep the report's structure from run to run and to state what changed since.
---
# The earlier reports of this task

`getPreviousTaskRunReports` returns the reports the earlier runs of THIS task wrote for the owner, newest first: the
day, the subject and the report text with its widget blocks and their values. Read the newest one before you write
your report:

- Keep its sections and widgets in the same order; update the values with this run's data.
- State what changed since that report: a KpiTile's `delta` from the earlier value, "3 new reviews since last week".
- Add or drop a section only when this run's data demands it, and say so in one line.
- When the result says the owner changed the task since, compose the report for the current task instead.
- The first report of a task has no predecessor: compose it from the task.
