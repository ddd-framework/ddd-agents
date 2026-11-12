<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The numbers a `ComposedView` tree shows — the figures written inline and the rows a resolved `$from` snapshot holds
 * — and which of them the prose around the block repeats (the eval finding
 * `PROSE_REPEATS_WIDGET_VALUES` of `scripts/evals/widgets-report.php`). Every number is kept in one canonical form
 * (`4.7`, `1234`, `-3`), so a prose figure matches in either locale (`4,7` / `4.7`, `1.234` / `1,234`).
 *
 * Not counted: ids, axis bounds and binding bookkeeping ({@see IGNORED_KEYS}, any key ending in `Id`), and whole numbers
 * below {@see MIN_COUNTED_WHOLE_NUMBER} — a single-digit count or star rating turns up in prose for other reasons.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewShownNumbers extends ValueObject
{
    /** Field names whose numbers are no shown figure. */
    public const array IGNORED_KEYS = ['id', 'min', 'max', 'toolCall', 'path', 'state', 'reason', 'resolvedAt', 'rowCount', 'cappedToRows', 'entityRef', 'entity', 'refState'];

    /** The smallest whole number counted; a number with decimals always counts. */
    public const int MIN_COUNTED_WHOLE_NUMBER = 10;

    /** A figure as written in prose or in a string value: digits with `.` / `,` groups and an optional sign. */
    public const string WRITTEN_NUMBER_PATTERN = '/(?<![\w.,])[-+−]?\d+(?:[.,]\d+)*/u';

    /** A string value that is one figure and nothing else (`"4,7"`, `"26 %"`, `"-3"`). */
    public const string NUMBER_STRING_PATTERN = '/^\s*[-+−]?\d+(?:[.,]\d+)*\s*%?\s*$/u';

    /** @var string[] The canonical numbers the tree shows, each once, in tree order. */
    public array $shownNumbers = [];

    /** @param array<mixed> $widgetFields */
    public static function fromWidgetFields(array $widgetFields): static
    {
        $composedViewShownNumbers = new static();
        $composedViewShownNumbers->collectShownNumbersOfValue($widgetFields);
        return $composedViewShownNumbers;
    }

    /**
     * The shown numbers that `$prose` writes as well, in the order of {@see $shownNumbers}.
     *
     * @return string[]
     */
    public function findNumbersRepeatedInProse(string $prose): array
    {
        $proseNumbers = [];
        preg_match_all(self::WRITTEN_NUMBER_PATTERN, $prose, $writtenNumberMatches);
        foreach ($writtenNumberMatches[0] as $writtenNumber) {
            foreach (self::readCanonicalNumbers($writtenNumber) as $canonicalNumber) {
                $proseNumbers[$canonicalNumber] = true;
            }
        }
        return array_values(array_filter($this->shownNumbers, static fn (string $shownNumber) => isset($proseNumbers[$shownNumber])));
    }

    /**
     * The canonical readings of one written figure: `4,7` → 4.7; `1.234` → 1234 and 1.234 (a three-digit group reads
     * either way); `1.234,5` → 1234.5 (of two separator kinds the last is the decimal one); `1.234.567` → 1234567.
     *
     * @return string[]
     */
    public static function readCanonicalNumbers(string $writtenNumber): array
    {
        $isNegative = str_starts_with($writtenNumber, '-') || str_starts_with($writtenNumber, '−');
        $digitsAndSeparators = (string)preg_replace('/^[-+−]/u', '', $writtenNumber);
        preg_match_all('/[.,]/', $digitsAndSeparators, $separatorMatches);
        $separators = $separatorMatches[0];
        $readings = [];
        if ($separators === []) {
            $readings[] = $digitsAndSeparators;
        } elseif (count(array_unique($separators)) === 2) {
            $decimalSeparator = $separators[count($separators) - 1];
            $thousandsSeparator = $decimalSeparator === '.' ? ',' : '.';
            $readings[] = str_replace([$thousandsSeparator, $decimalSeparator], ['', '.'], $digitsAndSeparators);
        } else {
            $digitGroups = explode($separators[0], $digitsAndSeparators);
            $groupsOfThreeDigits = array_filter(array_slice($digitGroups, 1), static fn (string $digitGroup) => strlen($digitGroup) === 3);
            if (count($groupsOfThreeDigits) === count($digitGroups) - 1) {
                $readings[] = implode('', $digitGroups);
            }
            if (count($separators) === 1) {
                $readings[] = str_replace($separators[0], '.', $digitsAndSeparators);
            }
        }
        $canonicalNumbers = [];
        foreach ($readings as $reading) {
            if (is_numeric($reading)) {
                $canonicalNumbers[] = self::formatCanonicalNumber(($isNegative ? -1 : 1) * (float)$reading);
            }
        }
        return $canonicalNumbers;
    }

    /** One number in its canonical form: no thousands separator, `.` as the decimal point, at most six decimals. */
    public static function formatCanonicalNumber(float $number): string
    {
        $canonicalNumber = number_format($number, 6, '.', '');
        $canonicalNumber = rtrim(rtrim($canonicalNumber, '0'), '.');
        return $canonicalNumber === '-0' ? '0' : $canonicalNumber;
    }

    /** Collects the shown numbers of one value of the tree, depth first; `$fieldName` is the key it stands under. */
    protected function collectShownNumbersOfValue(mixed $value, ?string $fieldName = null): void
    {
        if ($fieldName !== null && (in_array($fieldName, self::IGNORED_KEYS, true) || str_ends_with($fieldName, 'Id'))) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $nestedKey => $nestedValue) {
                // a list element stands under the key of its list
                $this->collectShownNumbersOfValue($nestedValue, is_string($nestedKey) ? $nestedKey : $fieldName);
            }
            return;
        }
        if (is_int($value) || is_float($value)) {
            $this->addShownNumber((float)$value);
            return;
        }
        if (is_string($value) && preg_match(self::NUMBER_STRING_PATTERN, $value) === 1 && preg_match(self::WRITTEN_NUMBER_PATTERN, trim($value), $writtenNumberMatch) === 1) {
            // an ambiguous `1.234` is shown in both readings: the prose of either locale repeats it
            foreach (self::readCanonicalNumbers($writtenNumberMatch[0]) as $canonicalNumber) {
                $this->addShownNumber((float)$canonicalNumber);
            }
        }
    }

    protected function addShownNumber(float $number): void
    {
        if ($number === floor($number) && abs($number) < self::MIN_COUNTED_WHOLE_NUMBER) {
            return;
        }
        $canonicalNumber = self::formatCanonicalNumber($number);
        if (!in_array($canonicalNumber, $this->shownNumbers, true)) {
            $this->shownNumbers[] = $canonicalNumber;
        }
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(implode(',', $this->shownNumbers));
    }
}
