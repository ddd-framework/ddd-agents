<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Kernel\ReferenceKernelSmokeTest;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * The two kernels ship `.env.dist` files, never a filled env file: every key-shaped variable is empty there, and the
 * values the package run needs are set in PHP where the kernel is built — {@see PackageTestEnvironment} for the test
 * kernel, {@see ReferenceKernelSmokeTest::FAKE_KEY_ENVIRONMENT} for the reference host.
 */
class EnvironmentDistFilesTest extends UnitTestCase
{
    /** A variable whose name ends like this holds a credential (a database user is half of a credential pair). */
    public const string KEY_SHAPED_NAME_PATTERN = '/(_KEY|_SECRET|_PASSWORD|_USER|_TOKEN)$/';

    public const string TEST_KERNEL_DIST_FILE = __DIR__ . '/../Support/kernel/.env.dist';

    public const string REFERENCE_KERNEL_DIST_FILE = __DIR__ . '/../../examples/reference-kernel/.env.dist';

    public function testEveryKeyShapedVariableIsEmptyInBothDistFiles(): void
    {
        foreach ([self::TEST_KERNEL_DIST_FILE, self::REFERENCE_KERNEL_DIST_FILE] as $distFile) {
            $filledKeyNames = [];
            foreach ($this->readDistValues($distFile) as $variableName => $value) {
                if (preg_match(self::KEY_SHAPED_NAME_PATTERN, $variableName) && $value !== '') {
                    $filledKeyNames[] = $variableName;
                }
            }
            self::assertSame([], $filledKeyNames, basename(dirname($distFile)) . '/.env.dist');
        }
    }

    public function testTheTestKernelDistFileListsExactlyTheNamesThePackageRunSets(): void
    {
        self::assertSame(
            array_keys(PackageTestEnvironment::DEFAULT_VALUES),
            array_keys($this->readDistValues(self::TEST_KERNEL_DIST_FILE))
        );
    }

    public function testTheSmokeTestSuppliesEveryKeyShapedVariableOfTheReferenceHost(): void
    {
        $keyShapedNames = [];
        foreach (array_keys($this->readDistValues(self::REFERENCE_KERNEL_DIST_FILE)) as $variableName) {
            if (preg_match(self::KEY_SHAPED_NAME_PATTERN, $variableName)) {
                $keyShapedNames[] = $variableName;
            }
        }
        self::assertSame($keyShapedNames, array_keys(ReferenceKernelSmokeTest::FAKE_KEY_ENVIRONMENT));
    }

    /** @return string[] */
    protected function readDistValues(string $distFile): array
    {
        self::assertFileExists($distFile);
        return (new Dotenv())->parse((string)file_get_contents($distFile), $distFile);
    }
}
