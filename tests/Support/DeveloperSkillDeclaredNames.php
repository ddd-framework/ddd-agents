<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every name a developer skill may write in backticks: what the package, the framework, the AI and Argus modules and
 * the reference kernel DECLARE (classes, methods, constants, properties, string literals such as config keys and tool
 * names, file and folder names), plus the class names of the libraries on the class path.
 *
 * {@see self::findWhyNotDeclared()} answers for one inline code span why it does not resolve — or null when it does.
 * The rules are deliberately the ones a reader applies: `Foo::bar()` needs a class `Foo` that declares or inherits
 * `bar`; a path needs a file; a config key needs its segments; a bare word needs a declaration of that word.
 */
class DeveloperSkillDeclaredNames
{
    /** File extensions read as PHP source */
    public const string PHP_EXTENSION = 'php';

    /** @var string[] File extensions read as text whose words count as names (config, agent skills, examples, the web half) */
    public const array TEXT_EXTENSIONS = ['md', 'yaml', 'yml', 'json', 'ts', 'tsx', 'neon', 'txt', 'xml', 'twig'];

    /** @var string[] Folders never read */
    public const array SKIPPED_DIRECTORY_NAMES = ['vendor', 'node_modules', '.git', 'var', 'dist', '.phpunit.cache'];

    /** A root's test folder: its class names and paths count (a maintainer text may name a test), its literals do not */
    public const string TESTS_DIRECTORY_NAME = 'tests';

    /**
     * @var string[] Words that name no declaration: PHP keywords and types, formats, HTTP verbs. A product or tool name
     * that a text has to mention goes to the allowlist file of the test with its reason, never here.
     */
    public const array LANGUAGE_WORDS = [
        'true', 'false', 'null', 'string', 'int', 'float', 'bool', 'array', 'object', 'mixed', 'void', 'never',
        'callable', 'iterable', 'self', 'static', 'parent', 'new', 'protected', 'public', 'private', 'final',
        'abstract', 'readonly', 'class', 'interface', 'trait', 'enum', 'function', 'const', 'use', 'namespace',
        'extends', 'implements', 'return', 'instanceof', 'require', 'require_once', 'include', 'php',
        'GET', 'POST', 'PUT', 'PATCH', 'DELETE',
    ];

    /** @var array<string, string[]> class, interface, trait or enum name => the files that declare it */
    public array $filesByClassName = [];

    /** @var array<string, true> */
    public array $methodNames = [];

    /** @var array<string, true> constants and enum cases */
    public array $constantNames = [];

    /** @var array<string, true> properties, parameters and variables, without the dollar sign */
    public array $variableNames = [];

    /** @var array<string, true> string literals of PHP files and the words of text files */
    public array $literals = [];

    /** @var array<string, true> file names with and without extension, and folder names */
    public array $fileAndDirectoryNames = [];

    /** @var array<string, true> file and folder paths relative to the root they were found below */
    public array $relativePaths = [];

    /** @var array<string, true> short class names of the libraries on the class path */
    public array $libraryClassNames = [];

    /** `vendor/composer/autoload_psr4.php`; read on the first name the class map does not know */
    protected ?string $composerPsr4File = null;

    protected bool $libraryPsr4DirectoriesAreRead = false;

    /** @var array<string, string> absolute path => content, of the PHP files read for a member lookup */
    protected array $phpFileContents = [];

    /**
     * @param string[] $sourceRootDirectories package roots: each is read below `src`, `config`, `examples`, `web` and
     *                                        `.claude/skills` (folder names only), plus its top-level files
     * @param string|null $composerClassMapFile `vendor/composer/autoload_classmap.php`, for the library class names
     */
    public static function fromSourceRoots(array $sourceRootDirectories, ?string $composerClassMapFile = null): static
    {
        $declaredNames = new static();
        foreach ($sourceRootDirectories as $sourceRootDirectory) {
            $declaredNames->readSourceRoot(rtrim($sourceRootDirectory, '/'));
        }
        if ($composerClassMapFile !== null && is_file($composerClassMapFile)) {
            $declaredNames->readLibraryClassNames($composerClassMapFile);
            $declaredNames->composerPsr4File = dirname($composerClassMapFile) . '/autoload_psr4.php';
        }
        return $declaredNames;
    }

    public function readSourceRoot(string $sourceRootDirectory): void
    {
        foreach (DeveloperSkillFiles::findFilesBelow($sourceRootDirectory) as $relativePath => $absolutePath) {
            $pathSegments = explode('/', $relativePath);
            if (array_intersect($pathSegments, self::SKIPPED_DIRECTORY_NAMES) !== []) {
                continue;
            }
            $isSkillFolderFile = str_starts_with($relativePath, '.claude/skills/');
            if ($isSkillFolderFile) {
                // only the skill's folder name counts: the texts under check must not vouch for themselves
                $this->fileAndDirectoryNames[$pathSegments[2]] = true;
                continue;
            }
            $this->recordPath($relativePath);
            $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
            if ($pathSegments[0] === self::TESTS_DIRECTORY_NAME) {
                if ($extension === self::PHP_EXTENSION) {
                    $this->readClassNames($absolutePath, (string)file_get_contents($absolutePath));
                }
                continue;
            }
            if ($relativePath === 'composer.json') {
                $this->readComposerPackageNames($absolutePath);
            }
            if ($extension === self::PHP_EXTENSION) {
                $this->readPhpFile($absolutePath);
            } elseif (in_array($extension, self::TEXT_EXTENSIONS, true)) {
                $this->readTextFile($absolutePath);
            }
        }
    }

    protected function recordPath(string $relativePath): void
    {
        $this->relativePaths[$relativePath] = true;
        $pathSegments = explode('/', $relativePath);
        $fileName = (string)array_pop($pathSegments);
        $this->fileAndDirectoryNames[$fileName] = true;
        $this->fileAndDirectoryNames[explode('.', $fileName, 2)[0]] = true;
        $directoryPath = '';
        foreach ($pathSegments as $directoryName) {
            $directoryPath = ltrim($directoryPath . '/' . $directoryName, '/');
            $this->relativePaths[$directoryPath] = true;
            $this->fileAndDirectoryNames[$directoryName] = true;
        }
    }

    protected function readPhpFile(string $absolutePath): void
    {
        $content = (string)file_get_contents($absolutePath);
        $this->readClassNames($absolutePath, $content);
        preg_match_all('~\bfunction\s+&?([A-Za-z_]\w*)\s*\(~', $content, $methodMatches);
        foreach ($methodMatches[1] as $methodName) {
            $this->methodNames[$methodName] = true;
        }
        preg_match_all('~@method\s+(?:static\s+)?\S+\s+([A-Za-z_]\w*)\s*\(~', $content, $documentedMethodMatches);
        foreach ($documentedMethodMatches[1] as $methodName) {
            $this->methodNames[$methodName] = true;
        }
        preg_match_all('~\bconst\s+(?:[\w|?\\\\]+\s+)?([A-Za-z_]\w*)\s*=~', $content, $constantMatches);
        foreach ($constantMatches[1] as $constantName) {
            $this->constantNames[$constantName] = true;
        }
        preg_match_all('~^\s*case\s+([A-Za-z_]\w*)\s*(?:=|;)~m', $content, $enumCaseMatches);
        foreach ($enumCaseMatches[1] as $enumCaseName) {
            $this->constantNames[$enumCaseName] = true;
        }
        preg_match_all('~\$([A-Za-z_]\w*)~', $content, $variableMatches);
        foreach ($variableMatches[1] as $variableName) {
            $this->variableNames[$variableName] = true;
        }
        preg_match_all('~\'([^\'\\\\\n]{1,160})\'~', $content, $singleQuotedMatches);
        foreach ($singleQuotedMatches[1] as $literal) {
            $this->literals[$literal] = true;
        }
        preg_match_all('~"([^"\\\\\n$]{1,160})"~', $content, $doubleQuotedMatches);
        foreach ($doubleQuotedMatches[1] as $literal) {
            $this->literals[$literal] = true;
        }
    }

    protected function readClassNames(string $absolutePath, string $content): void
    {
        preg_match_all('~^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)~m', $content, $classMatches);
        foreach ($classMatches[1] as $className) {
            $this->filesByClassName[$className][] = $absolutePath;
        }
    }

    /** The package's own Composer name and the names it requires count as literals (`mgamadeus/ddd-ai`) */
    protected function readComposerPackageNames(string $composerFile): void
    {
        $composerDefinition = json_decode((string)file_get_contents($composerFile), true) ?: [];
        $packageNames = array_merge([(string)($composerDefinition['name'] ?? '')], array_keys($composerDefinition['require'] ?? []), array_keys($composerDefinition['require-dev'] ?? []));
        foreach ($packageNames as $packageName) {
            $this->literals[$packageName] = true;
        }
    }

    protected function readTextFile(string $absolutePath): void
    {
        $content = (string)file_get_contents($absolutePath);
        preg_match_all('~[A-Za-z_][\w.-]*[\w]|[A-Za-z_]~', $content, $wordMatches);
        foreach ($wordMatches[0] as $word) {
            $this->literals[$word] = true;
            foreach (preg_split('~[.-]~', $word) ?: [] as $wordPart) {
                $this->literals[$wordPart] = true;
            }
        }
    }

    protected function readLibraryClassNames(string $composerClassMapFile): void
    {
        $vendorDirectory = dirname($composerClassMapFile, 2);
        $classMap = require $composerClassMapFile;
        foreach ($classMap as $className => $classFile) {
            if (!str_starts_with((string)$classFile, $vendorDirectory . '/')) {
                continue;
            }
            $classNameSegments = explode('\\', (string)$className);
            $this->libraryClassNames[(string)end($classNameSegments)] = true;
        }
    }

    /**
     * The class map of a plain `composer install` lists no library class (only an optimized dump does): the library
     * class names are then the PHP file names below the PSR-4 directories of the vendor folder.
     */
    protected function readLibraryClassNamesFromPsr4Directories(): void
    {
        $this->libraryPsr4DirectoriesAreRead = true;
        if ($this->composerPsr4File === null || !is_file($this->composerPsr4File)) {
            return;
        }
        $vendorDirectory = dirname($this->composerPsr4File, 2);
        $directoriesByNamespacePrefix = require $this->composerPsr4File;
        foreach ($directoriesByNamespacePrefix as $psr4Directories) {
            foreach ((array)$psr4Directories as $psr4Directory) {
                if (!str_starts_with((string)$psr4Directory, $vendorDirectory . '/') || !is_dir((string)$psr4Directory)) {
                    continue;
                }
                $psr4Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string)$psr4Directory, FilesystemIterator::SKIP_DOTS));
                foreach ($psr4Files as $psr4File) {
                    if ($psr4File->isFile() && $psr4File->getExtension() === self::PHP_EXTENSION) {
                        $this->libraryClassNames[$psr4File->getBasename('.' . self::PHP_EXTENSION)] = true;
                    }
                }
            }
        }
    }

    public function isClassName(string $name): bool
    {
        if (isset($this->filesByClassName[$name]) || isset($this->libraryClassNames[$name]) || class_exists($name, false) || interface_exists($name, false)) {
            return true;
        }
        if (!$this->libraryPsr4DirectoriesAreRead) {
            $this->readLibraryClassNamesFromPsr4Directories();
        }
        return isset($this->libraryClassNames[$name]);
    }

    /**
     * Whether the class, one of its parents, interfaces or traits declares the member.
     *
     * @param string[] $visitedClassNames
     */
    public function classDeclaresMember(string $className, string $memberName, array &$visitedClassNames = []): bool
    {
        if (in_array($className, $visitedClassNames, true)) {
            return false;
        }
        $visitedClassNames[] = $className;
        // `PREFIX_*` names a family of members: one member that starts so is enough
        $quotedMemberName = preg_quote(rtrim($memberName, '*'), '~') . (str_ends_with($memberName, '*') ? '\w*' : '');
        foreach ($this->filesByClassName[$className] ?? [] as $classFile) {
            $content = $this->phpFileContents[$classFile] ??= (string)file_get_contents($classFile);
            $memberPatterns = [
                '~\bfunction\s+&?' . $quotedMemberName . '\s*\(~',
                '~\bconst\s+(?:[\w|?\\\\]+\s+)?' . $quotedMemberName . '\s*=~',
                '~^\s*case\s+' . $quotedMemberName . '\s*(?:=|;)~m',
                '~\$' . $quotedMemberName . '\b~',
                '~@method\s+(?:static\s+)?\S+\s+' . $quotedMemberName . '\s*\(~',
            ];
            foreach ($memberPatterns as $memberPattern) {
                if (preg_match($memberPattern, $content) === 1) {
                    return true;
                }
            }
            foreach ($this->findParentClassNames($content) as $parentClassName) {
                if ($this->classDeclaresMember($parentClassName, $memberName, $visitedClassNames)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return string[] short names of the classes, interfaces and traits a class file extends, implements or uses */
    protected function findParentClassNames(string $classFileContent): array
    {
        $parentClassNames = [];
        preg_match_all('~\b(?:extends|implements)\s+([\w\\\\]+(?:\s*,\s*[\w\\\\]+)*)~', $classFileContent, $inheritanceMatches);
        preg_match_all('~^[ \t]+use\s+([\w\\\\]+(?:\s*,\s*[\w\\\\]+)*)\s*[;{]~m', $classFileContent, $traitMatches);
        foreach (array_merge($inheritanceMatches[1], $traitMatches[1]) as $nameList) {
            foreach (preg_split('~\s*,\s*~', trim($nameList)) ?: [] as $qualifiedName) {
                $nameSegments = explode('\\', $qualifiedName);
                $parentClassNames[] = (string)end($nameSegments);
            }
        }
        return array_values(array_unique($parentClassNames));
    }

    /**
     * Why the content of one inline code span does not resolve to a declaration, or null when it does (or when it is
     * no name at all: prose, a value, a command line).
     *
     * @param string[] $additionalDirectories folders a path may also be relative to (the skill folder, the skills folder)
     */
    public function findWhyNotDeclared(string $codeSpan, array $additionalDirectories = []): ?string
    {
        // placeholders stand for a value the reader fills in
        $codeSpan = trim($codeSpan);
        if (str_contains($codeSpan, '/') && preg_match('~^([^<{\s]*?)/?[^/<{\s]*(?:\{\{|<)~', $codeSpan, $pathBeforePlaceholderMatch) === 1) {
            // a path with a placeholder segment: the part above the first placeholder has to exist
            $pathBeforePlaceholder = trim($pathBeforePlaceholderMatch[1], '/');
            return $pathBeforePlaceholder === '' ? null : $this->findWhyPathNotFound($pathBeforePlaceholder, $additionalDirectories);
        }
        $codeSpan = (string)preg_replace('~\{\{[^}]*\}\}|<[^<>`]*>~', '', $codeSpan);
        $codeSpan = trim($codeSpan);
        if ($codeSpan === '' || str_contains($codeSpan, '…') || str_contains($codeSpan, '...')) {
            return null;
        }
        if (preg_match('~^#\[\\\\?([\w\\\\]+)~', $codeSpan, $attributeMatch) === 1) {
            $attributeNameSegments = explode('\\', $attributeMatch[1]);
            $attributeClassName = (string)end($attributeNameSegments);
            return $this->isClassName($attributeClassName) ? null : 'no attribute class `' . $attributeClassName . '`';
        }
        if (preg_match('~\s~', $codeSpan) === 1) {
            return $this->findWhyPhraseNotDeclared($codeSpan);
        }
        return $this->findWhyNameNotDeclared($codeSpan, $additionalDirectories);
    }

    /** A span with spaces: only its parts that are unmistakably code are checked */
    protected function findWhyPhraseNotDeclared(string $phrase): ?string
    {
        // the arguments of a call example are the caller's own variables, not names of the package
        $phrase = (string)preg_replace('~\([^()]*\)~', '()', $phrase);
        preg_match_all('~[A-Za-z_\\\\][\w\\\\]*::\$?\w+|\$[A-Za-z_]\w*|\b[A-Z][a-z0-9]+(?:[A-Z][A-Za-z0-9]*)+\b|\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b~', $phrase, $codePartMatches);
        foreach ($codePartMatches[0] as $codePart) {
            $reason = $this->findWhyNameNotDeclared($codePart, []);
            if ($reason !== null) {
                return $reason;
            }
        }
        return null;
    }

    /**
     * @param string[] $additionalDirectories
     */
    protected function findWhyNameNotDeclared(string $name, array $additionalDirectories): ?string
    {
        // call syntax, attribute brackets, leading member operators, trailing punctuation
        if (str_contains($name, '://') || preg_match('~^(?:\\\\[nrt0])+$~', $name) === 1) {
            return null; // a DSN or URL, an escape sequence
        }
        $name = (string)preg_replace('~\(.*\)$~', '', $name);
        $name = trim($name, " \t,;:.");
        $name = (string)preg_replace('~^(?:->|::|\\\\|\?)~', '', $name);
        $name = (string)preg_replace('~(?:\[\])+$~', '', $name);
        if ($name === '' || in_array($name, self::LANGUAGE_WORDS, true)) {
            return null;
        }
        if (preg_match('~^([A-Za-z_][\w.]*)=~', $name, $assignedNameMatch) === 1) {
            $name = $assignedNameMatch[1]; // `key=value`: the key is the name
        }
        if (isset($this->literals[$name]) || isset($this->literals[$name . '.'])) {
            return null;
        }
        if (preg_match('~^[\d\W]~', $name) === 1 && !str_starts_with($name, '$') && !str_starts_with($name, '.')) {
            return null; // a number, a value, an operator
        }
        if (str_contains($name, '::')) {
            return $this->findWhyClassMemberNotDeclared($name);
        }
        if (str_contains($name, '/') || preg_match('~\.(?:php|md|yaml|yml|json|tsv|ts|tsx|neon|xml|txt|dist|lock)$~', $name) === 1) {
            return $this->findWhyPathNotFound($name, $additionalDirectories);
        }
        if (str_contains($name, '\\')) {
            $nameSegments = explode('\\', $name);
            $shortClassName = (string)end($nameSegments);
            return $this->isClassName($shortClassName) || isset($this->fileAndDirectoryNames[$shortClassName])
                ? null
                : 'no class or namespace folder `' . $shortClassName . '`';
        }
        if (str_starts_with($name, '$') && str_contains($name, '->')) {
            // `$object->member->method()`: the variable is the reader's own, every member has to be declared
            $memberNames = explode('->', (string)preg_replace('~\([^()]*\)~', '', $name));
            array_shift($memberNames);
            foreach ($memberNames as $memberName) {
                if ($memberName !== '' && !$this->isDeclaredWord($memberName)) {
                    return 'no property or method `' . $memberName . '`';
                }
            }
            return null;
        }
        if (str_starts_with($name, '$')) {
            return isset($this->variableNames[substr($name, 1)]) ? null : 'no property or parameter of that name';
        }
        if ($this->isDeclaredWord($name)) {
            return null;
        }
        if (preg_match('~^(_?)([A-Za-z0-9_]*?)(_?\*?)$~', $name, $fragmentMatch) === 1 && ($fragmentMatch[1] !== '' || $fragmentMatch[3] !== '')) {
            // a name family: `KIND_*`, `TaskPlanUpdate*` (every name that starts so) or `_REFUSED` (every constant that ends so)
            return $this->hasNameWithFragment($fragmentMatch[2], $fragmentMatch[1] !== '', str_ends_with($fragmentMatch[3], '*'))
                ? null
                : 'no declared name matches the name family';
        }
        if (preg_match('~[.-]~', $name) === 1) {
            foreach (preg_split('~[.-]~', $name) ?: [] as $nameSegment) {
                if ($nameSegment !== '' && !$this->isDeclaredWord($nameSegment)) {
                    return 'segment `' . $nameSegment . '` is declared nowhere';
                }
            }
            return null;
        }
        return 'declared nowhere';
    }

    protected function hasNameWithFragment(string $fragment, bool $isSuffix, bool $isPrefix): bool
    {
        if ($fragment === '') {
            return false;
        }
        if ($isSuffix) {
            foreach (array_keys($this->constantNames) as $constantName) {
                if (str_ends_with((string)$constantName, '_' . $fragment)) {
                    return true;
                }
            }
        }
        if ($isPrefix) {
            foreach ([$this->constantNames, $this->filesByClassName, $this->methodNames, $this->literals] as $declaredNames) {
                foreach (array_keys($declaredNames) as $declaredName) {
                    if (str_starts_with((string)$declaredName, $fragment) && strlen((string)$declaredName) > strlen($fragment)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    protected function isDeclaredWord(string $word): bool
    {
        return $this->isClassName($word)
            || isset($this->methodNames[$word])
            || isset($this->constantNames[$word])
            || isset($this->variableNames[$word])
            || isset($this->literals[$word])
            || isset($this->fileAndDirectoryNames[$word])
            || function_exists($word)
            || ini_get($word) !== false;
    }

    protected function findWhyClassMemberNotDeclared(string $classAndMember): ?string
    {
        [$qualifiedClassName, $memberName] = explode('::', $classAndMember, 2);
        $classNameSegments = explode('\\', $qualifiedClassName);
        $className = (string)end($classNameSegments);
        $memberName = ltrim($memberName, '$');
        if (strlen($className) === 1) {
            return $this->isDeclaredWord($memberName) ? null : 'member `' . $memberName . '` is declared nowhere'; // `X::` stands for any class
        }
        if (in_array($className, ['self', 'static', 'parent'], true)) {
            return $this->isDeclaredWord($memberName) ? null : 'member `' . $memberName . '` is declared nowhere';
        }
        if (!$this->isClassName($className)) {
            return 'no class `' . $className . '`';
        }
        if ($memberName === 'class' || !isset($this->filesByClassName[$className])) {
            return null; // a library or PHP class: its members are not read
        }
        return $this->classDeclaresMember($className, $memberName)
            ? null
            : '`' . $className . '` exists, but neither it nor a parent declares `' . $memberName . '`';
    }

    /**
     * @param string[] $additionalDirectories
     */
    protected function findWhyPathNotFound(string $path, array $additionalDirectories): ?string
    {
        $path = trim($path, '/');
        if (str_contains($path, '*')) {
            $pathPattern = '~^' . str_replace(['\*\*/', '\*\*', '\*'], ['(?:[^/]+/)*', '.*', '[^/]*'], preg_quote($path, '~')) . '$~';
            foreach (array_keys($this->relativePaths) as $relativePath) {
                if (preg_match($pathPattern, $relativePath) === 1) {
                    return null;
                }
            }
            return 'the pattern matches no file';
        }
        if (isset($this->relativePaths[$path]) || isset($this->fileAndDirectoryNames[$path]) || isset($this->literals[$path])) {
            return null;
        }
        foreach ($additionalDirectories as $additionalDirectory) {
            if (file_exists(rtrim($additionalDirectory, '/') . '/' . $path)) {
                return null;
            }
        }
        // a path given from a folder inside a root (`Entities/Skills/AISkill.php`, `AI/Skills`)
        $pathSuffix = '/' . $path;
        foreach (array_keys($this->relativePaths) as $relativePath) {
            if (str_ends_with($relativePath, $pathSuffix)) {
                return null;
            }
        }
        return 'no such file or folder';
    }
}
