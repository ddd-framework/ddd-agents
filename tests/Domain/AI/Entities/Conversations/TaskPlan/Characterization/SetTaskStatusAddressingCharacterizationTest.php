<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusAddressCheck;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanHolidayPlanFixture as Holiday;
use PHPUnit\Framework\Attributes\DataProvider;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;

/**
 * `set_task_status` addressing, tolerant → strict. The earlier characterization pinned the tolerant addressing (the
 * number path, a title copied behind it cut off, a unique item text, and the values that resolved by accident: a leading
 * zero, a JSON number or boolean cast to a string). The addressing is now strict: only a conforming key
 * that names exactly one item addresses it; every other value REJECTS THE WHOLE CALL with the rejection block. Each row
 * below is the tolerant row flipped to its strict expectation; what the row resolved to under the tolerant addressing
 * stays in its name ("K0: …").
 */
class SetTaskStatusAddressingCharacterizationTest extends TaskPlanCharacterizationTestCase
{
    /** The open keys of the address book, as the rejection block lists them. */
    protected const string OPEN_KEYS_LINE = "Your open items: 1.1 'Review' · 2.1 'Review' · 3 '24/7 support' · 4 'Keywords' · 5 'Competitors' · 6 'Goals' · 7.1 'Update Opening Hours' · 7.2 'Update Contact Details'";

    /**
     * 1 Posts › 1.1 Review · 2 Replies › 2.1 Review · 3 24/7 support · 4 Keywords · 5 Competitors · 6 Goals ·
     * 7 Profile › 7.1 Update Opening Hours, 7.2 Update Contact Details
     */
    protected function addressBookPlan(): TaskPlan
    {
        return Holiday::plan([
            Holiday::container('Posts', [Holiday::item('Review')]),
            Holiday::container('Replies', [Holiday::item('Review')]),
            Holiday::item('24/7 support'),
            Holiday::item('Keywords'),
            Holiday::item('Competitors'),
            Holiday::item('Goals'),
            Holiday::container('Profile', [Holiday::item('Update Opening Hours'), Holiday::item('Update Contact Details')]),
        ]);
    }

    /**
     * One `done` tick on a fresh address-book plan: [updated count, feedback, the number paths whose stored status is
     * done afterwards].
     *
     * @param array<string, mixed> $update
     * @return array{0: int, 1: string[], 2: string[]}
     */
    protected function tick(array $update): array
    {
        $taskPlan = $this->addressBookPlan();
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [$update + ['status' => 'done', 'statusNote' => 'saved']]],
            $taskPlan,
            0
        );
        $doneNumberPaths = [];
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->status === TaskPlanItem::STATUS_DONE) {
                $doneNumberPaths[] = $taskPlanItemAddress->numberPath;
            }
        }
        return [$result['updatedCount'], $result['feedback'], $doneNumberPaths];
    }

    /** The whole-call rejection a strict tick answers with: nothing updated, the block, nothing done. */
    protected static function rejected(string ...$rejectionLines): array
    {
        return [0, [
            SetTaskStatusAddressCheck::REJECTION_HEADER . "\n"
            . implode("\n", array_map(static fn (string $rejectionLine): string => '- ' . $rejectionLine, $rejectionLines)) . "\n"
            . self::OPEN_KEYS_LINE,
        ], []];
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function plainKeys(): array
    {
        return [
            'the number path (K0: resolved 7.2 — the key, unchanged)' => ['7.2', '7.2'],
            'a top-level number path (K0: resolved 4 — the key, unchanged)' => ['4', '4'],
        ];
    }

    #[DataProvider('plainKeys')]
    public function testAPlainKeyStillAddressesItsItem(string $taskAddress, string $resolvedKey): void
    {
        self::assertSame([1, [], [$resolvedKey]], $this->tick(['task' => $taskAddress]));
    }

    /**
     * The non-key rows of the tolerant `resolvingAddresses()`, each flipped from "resolves" to the whole-call rejection.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function formerlyResolvingAddresses(): array
    {
        return [
            'the number path with its own title behind it (K0: resolved 7.2)' => ['7.2 Update Contact Details', "Task '7.2 Update Contact Details' is not a key — send \"task\": \"7.2\" (the number only, as a string)."],
            'the number path with another item\'s title behind it (K0: resolved 7.2)' => ['7.2 Goals', "Task '7.2 Goals' is not a key — send \"task\": \"7.2\" (the number only, as a string)."],
            'the number path padded with whitespace (K0: resolved 7.2)' => ['  7.2  ', "Task '  7.2  ' is not a key — the number only, digits joined by single dots; send \"task\": \"7.2\"."],
            'a unique item text (K0: resolved 7.2)' => ['Update Contact Details', "Task 'Update Contact Details' is not a key — address an item by its number from \"Your task plan\"."],
            'a unique item text in another case (K0: resolved 7.2)' => ['UPDATE CONTACT DETAILS', "Task 'UPDATE CONTACT DETAILS' is not a key — address an item by its number from \"Your task plan\"."],
            'a text starting with digits (K0: resolved 3)' => ['24/7 support', "Task '24/7 support' is not a key — address an item by its number from \"Your task plan\"."],
        ];
    }

    #[DataProvider('formerlyResolvingAddresses')]
    public function testAFormerlyResolvingAddressRejectsTheWholeCall(mixed $taskAddress, string $rejectionLine): void
    {
        self::assertSame(self::rejected($rejectionLine), $this->tick(['task' => $taskAddress]));
    }

    public function testALeadingZeroRejectsTheWholeCall(): void
    {
        // formerly "07.2" resolved 7.2 (a leading zero in a segment was accepted)
        self::assertSame(
            self::rejected("Task '07.2' is not a key — a number never starts with 0; send \"task\": \"7.2\"."),
            $this->tick(['task' => '07.2'])
        );
    }

    public function testAJsonFloatRejectsTheWholeCall(): void
    {
        // formerly the JSON number 7.2 was cast to "7.2" and resolved 7.2. A decimal suggests no key — 7.10 arrives as 7.1
        self::assertSame(self::rejected('Task 7.2 was sent as a number — send the key as a string, exactly as "Your task plan" shows it.'), $this->tick(['task' => 7.2]));
    }

    public function testAJsonIntegerRejectsTheWholeCall(): void
    {
        // formerly the JSON number 4 was cast to "4" and resolved 4
        self::assertSame(self::rejected('Task 4 was sent as a number — send "task": "4".'), $this->tick(['task' => 4]));
    }

    public function testAJsonTrueRejectsTheWholeCall(): void
    {
        // formerly `(string) true` was "1": the done landed on container 1 — whose subtask is still open — without a word
        self::assertSame(
            // the example is the plan's first open key, never a fixed one
            self::rejected('Task true was sent as a boolean — send the item\'s number from "Your task plan" as a string ("task": "1.1").'),
            $this->tick(['task' => true])
        );
    }

    /**
     * The tolerant `unresolvableAddresses()`: each skipped its own update with "not found (or ambiguous)"; now each
     * rejects the whole call with the line of its address class.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unresolvableAddresses(): array
    {
        return [
            'a text two items share (K0: not found)' => ['Review', "Task 'Review' is not a key — address an item by its number from \"Your task plan\"."],
            'a number path that does not exist, with a title (K0: not found)' => ['7.2.11 Update Contact Details', "Task '7.2.11 Update Contact Details' is not a key — address an item by its number from \"Your task plan\"."],
            'a number path out of range (K0: not found)' => ['9', "Task '9' does not exist in your plan."],
            'a subtask out of range (K0: not found)' => ['7.3', "Task '7.3' does not exist in your plan."],
            'a trailing dot (K0: not found)' => ['7.2.', "Task '7.2.' is not a key — the number only, digits joined by single dots; send \"task\": \"7.2\"."],
            'a dash instead of the dot (K0: not found)' => ['7-2', "Task '7-2' is not a key — the number only, digits joined by single dots; send \"task\": \"7.2\"."],
            'a text no item carries (K0: not found)' => ['Update the logo', "Task 'Update the logo' is not a key — address an item by its number from \"Your task plan\"."],
        ];
    }

    #[DataProvider('unresolvableAddresses')]
    public function testAnUnresolvableAddressRejectsTheWholeCall(string $taskAddress, string $rejectionLine): void
    {
        self::assertSame(self::rejected($rejectionLine), $this->tick(['task' => $taskAddress]));
    }

    public function testTheAliasKeysItemAndNumberCarryAnAddressUnderTheSameRules(): void
    {
        // formerly both resolved 7.2 — the alias stays, its VALUE follows the key rule
        self::assertSame([1, [], ['7.2']], $this->tick(['item' => '7.2']));
        self::assertSame(
            self::rejected("Task 'Update Contact Details' is not a key — address an item by its number from \"Your task plan\"."),
            $this->tick(['number' => 'Update Contact Details'])
        );
    }

    public function testAMissingOrNullTaskRejectsTheWholeCall(): void
    {
        // formerly "Update 1 rejected: no 'task' address." — that update only
        $noTask = self::rejected('Update 1 has no "task" — send the item\'s number from "Your task plan" as a string.');
        self::assertSame($noTask, $this->tick([]));
        self::assertSame($noTask, $this->tick(['task' => null]));
    }

    public function testAListOrAnObjectRejectsTheWholeCall(): void
    {
        self::assertSame(
            self::rejected('Task ["7","7.2"] (update 1) is a list — each update names ONE item by its number, as a string.'),
            $this->tick(['task' => ['7', '7.2']])
        );
        self::assertSame(
            self::rejected('Task {"id":"7.2"} (update 1) is an object — each update names ONE item by its number, as a string.'),
            $this->tick(['task' => ['id' => '7.2']])
        );
    }

    public function testOneBadAddressRejectsTheWholeCallAndNothingApplies(): void
    {
        // formerly an unresolvable address cost only its own update — 7.2 and 4 applied, 'Review' was skipped. Now the whole
        // call is rejected, every offending update named, nothing applied (4 included), no plan stamp
        $taskPlan = $this->addressBookPlan();
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [
            ['task' => '7.2 Update Contact Details', 'status' => 'done', 'statusNote' => 'saved'],
            ['task' => 'Review', 'status' => 'done', 'statusNote' => 'read'],
            ['task' => '4', 'status' => 'done', 'statusNote' => 'kept'],
        ]],
            $taskPlan,
            0
        );

        self::assertSame(0, $result['updatedCount']);
        self::assertSame(self::rejected(
            "Task '7.2 Update Contact Details' is not a key — send \"task\": \"7.2\" (the number only, as a string).",
            "Task 'Review' is not a key — address an item by its number from \"Your task plan\".",
        )[1], $result['feedback']);
        foreach (['7.2', '4', '1.1', '2.1'] as $numberPath) {
            self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getItemByNumberPath($numberPath)->status, $numberPath);
        }
        self::assertNull($taskPlan->updatedAtTurnInitiatorMessageIndex);
    }
}
