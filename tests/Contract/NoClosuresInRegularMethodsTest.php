<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Ratchet: no NEW closure in the package source. The rule (the first host application's code rule):
 * in an ordinary method a closure is forbidden — no closure parameter (a `callable` / `Closure` typed parameter), no
 * returned closure (a `callable` / `Closure` return type), no local lambda (`function () use (…)`, `fn () =>` assigned,
 * passed on or returned). Allowed: a short callback handed DIRECTLY to a sorting / filtering / mapping primitive
 * ({@see self::ALLOWED_CALLBACK_FUNCTIONS}, {@see self::ALLOWED_CALLBACK_METHODS}).
 *
 * Today's violations are pinned per file in `closure-ratchet.txt` (`<path below src/> <count>`); a file may only go
 * down. A file that is not listed allows none. Lower the pinned count when a closure is removed.
 */
class NoClosuresInRegularMethodsTest extends UnitTestCase
{
    /** @var string[] Functions whose callback argument may be a closure */
    public const array ALLOWED_CALLBACK_FUNCTIONS = ['usort', 'uasort', 'uksort', 'array_filter', 'array_map'];

    /** @var string[] Collection methods (sort, filter, map) whose callback argument may be a closure */
    public const array ALLOWED_CALLBACK_METHODS = ['sort', 'usort', 'filter', 'map'];

    public const string RATCHET_FILE = __DIR__ . '/closure-ratchet.txt';

    public function testNoFileHasMoreClosuresThanPinned(): void
    {
        $pinnedCountsByPath = $this->readPinnedCounts();
        $overPinnedPaths = [];
        $totalCount = 0;
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            $violationCount = count($this->findViolations((string)file_get_contents($absolutePath)));
            $totalCount += $violationCount;
            if ($violationCount > ($pinnedCountsByPath[$relativePath] ?? 0)) {
                $overPinnedPaths[] = sprintf('%s: %d (pinned %d)', $relativePath, $violationCount, $pinnedCountsByPath[$relativePath] ?? 0);
            }
        }
        self::assertSame([], $overPinnedPaths, 'new closure in a regular method — write a named method or pass a value object');
        self::assertLessThanOrEqual(array_sum($pinnedCountsByPath), $totalCount);
    }

    public function testThePinnedListHoldsNoStaleCounts(): void
    {
        $sourceFiles = AgentPackageSourceRoots::findPhpFiles();
        $staleEntries = [];
        foreach ($this->readPinnedCounts() as $relativePath => $pinnedCount) {
            $actualCount = isset($sourceFiles[$relativePath])
                ? count($this->findViolations((string)file_get_contents($sourceFiles[$relativePath])))
                : 0;
            if ($actualCount < $pinnedCount) {
                $staleEntries[] = sprintf('%s: %d (pinned %d) — lower the pin', $relativePath, $actualCount, $pinnedCount);
            }
        }
        self::assertSame([], $staleEntries);
    }

    public function testTheDetectorTellsAllowedCallbacksFromViolations(): void
    {
        $source = <<<'PHP'
            <?php
            class Example {
                public function allowed(array $values): array {
                    usort($values, static fn (int $a, int $b): int => $a <=> $b);
                    self::function('a method named like the keyword');
                    $values = array_filter($values, fn (int $value): bool => $value > 0);
                    return array_map(static function (int $value): int { return $value * 2; }, $values);
                }
                public function violations(callable $resolver, ?\Closure $other): \Closure {
                    $double = fn (int $value): int => $value * 2;
                    $this->apply(function () use (&$double) { return $double; });
                    return static fn () => $resolver();
                }
            }
            PHP;
        self::assertSame(
            ['closureParameter', 'closureParameter', 'closureReturnType', 'closureExpression', 'closureExpression', 'closureExpression'],
            array_column($this->findViolations($source), 'kind')
        );
    }

    /**
     * @return array<int, array{kind: string, line: int}> every violation of the rule in one PHP source
     */
    public function findViolations(string $phpSource): array
    {
        $tokens = $this->readSignificantTokens($phpSource);
        $violations = [];
        foreach ($tokens as $index => $token) {
            $tokenId = is_array($token) ? $token[0] : null;
            if ($this->isMemberName($tokens, $index)) {
                continue;
            }
            if ($tokenId === T_FUNCTION && !$this->isClosureStart($tokens, $index)) {
                foreach ($this->findClosureTypesInSignature($tokens, $index) as $violation) {
                    $violations[] = $violation;
                }
            } elseif (($tokenId === T_FUNCTION || $tokenId === T_FN) && !$this->isAllowedCallback($tokens, $index)) {
                $violations[] = ['kind' => 'closureExpression', 'line' => $token[2]];
            }
        }
        return $violations;
    }

    /** @return array<int, array{0: int, 1: string, 2: int}|string> the tokens without whitespace and comments */
    protected function readSignificantTokens(string $phpSource): array
    {
        $significantTokens = [];
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significantTokens[] = $token;
        }
        return $significantTokens;
    }

    /** Whether the token at `$index` is a member NAME spelled like a keyword (`self::function(`, `function function(`). */
    protected function isMemberName(array $tokens, int $index): bool
    {
        $previousToken = $tokens[$index - 1] ?? null;
        return is_array($previousToken)
            && in_array($previousToken[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true);
    }

    /** Whether the `function` token at `$index` starts a closure (`function (` / `function &(`), not a declaration. */
    protected function isClosureStart(array $tokens, int $index): bool
    {
        $nextToken = $tokens[$index + 1] ?? null;
        if ($nextToken === '&' || (is_array($nextToken) && $nextToken[1] === '&')) {
            $nextToken = $tokens[$index + 2] ?? null;
        }
        return $nextToken === '(';
    }

    /**
     * The closure types of a declared function's signature: each parameter typed `callable` / `Closure`, and a return
     * type `callable` / `Closure`.
     *
     * @return array<int, array{kind: string, line: int}>
     */
    protected function findClosureTypesInSignature(array $tokens, int $functionIndex): array
    {
        $violations = [];
        $index = $functionIndex + 1;
        while (isset($tokens[$index]) && $tokens[$index] !== '(') {
            $index++;
        }
        $depth = 0;
        for (; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $this->isClosureTypeToken($token)) {
                $violations[] = ['kind' => 'closureParameter', 'line' => $token[2]];
            }
        }
        $index++;
        if (($tokens[$index] ?? null) !== ':') {
            return $violations;
        }
        for ($index++; isset($tokens[$index]) && $tokens[$index] !== '{' && $tokens[$index] !== ';'; $index++) {
            if ($this->isClosureTypeToken($tokens[$index])) {
                $violations[] = ['kind' => 'closureReturnType', 'line' => $tokens[$index][2]];
                break;
            }
        }
        return $violations;
    }

    protected function isClosureTypeToken(mixed $token): bool
    {
        if (!is_array($token)) {
            return false;
        }
        if ($token[0] === T_CALLABLE) {
            return true;
        }
        return in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
            && strtolower(ltrim($token[1], '\\')) === 'closure';
    }

    /**
     * Whether the closure at `$index` is an argument written directly into the call of an allowed primitive (the
     * token before it — `static` skipped — is the call's `(` or a `,` at the call's own depth).
     */
    protected function isAllowedCallback(array $tokens, int $index): bool
    {
        $previousIndex = $index - 1;
        if (is_array($tokens[$previousIndex] ?? null) && $tokens[$previousIndex][0] === T_STATIC) {
            $previousIndex--;
        }
        $previousToken = $tokens[$previousIndex] ?? null;
        if ($previousToken !== '(' && $previousToken !== ',') {
            return false;
        }
        $depth = 0;
        for ($cursor = $previousIndex; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if ($token === ')' || $token === ']') {
                $depth++;
            } elseif ($token === '[') {
                if ($depth === 0) {
                    return false;
                }
                $depth--;
            } elseif ($token === '(') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                return $this->isAllowedCallName($tokens, $cursor);
            }
        }
        return false;
    }

    /** Whether the `(` at `$openParenthesisIndex` opens the argument list of an allowed function or method. */
    protected function isAllowedCallName(array $tokens, int $openParenthesisIndex): bool
    {
        $nameToken = $tokens[$openParenthesisIndex - 1] ?? null;
        if (!is_array($nameToken) || !in_array($nameToken[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }
        $name = strtolower(ltrim($nameToken[1], '\\'));
        $tokenBeforeName = $tokens[$openParenthesisIndex - 2] ?? null;
        $isMethodCall = is_array($tokenBeforeName) && in_array($tokenBeforeName[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        return $isMethodCall
            ? in_array($name, self::ALLOWED_CALLBACK_METHODS, true)
            : in_array($name, self::ALLOWED_CALLBACK_FUNCTIONS, true);
    }

    /** @return array<string, int> pinned violation count per path below src/ */
    protected function readPinnedCounts(): array
    {
        $pinnedCountsByPath = [];
        foreach (file(self::RATCHET_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            [$relativePath, $pinnedCount] = explode(' ', $line, 2);
            $pinnedCountsByPath[$relativePath] = (int)$pinnedCount;
        }
        return $pinnedCountsByPath;
    }
}
