<?php
/**
 * The purpose of this class is only to find changes that should be made.
 * i.e. classes and namespaces to change.
 * Those recorded are updated in a later step.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;

class ChangeEnumerator
{

    protected string $namespacePrefix;
    protected string $classmapPrefix;
    /**
     *
     * @var string[]
     */
    protected array $excludePackagesFromPrefixing = array();

    /** @var string[]  */
    protected array $excludeNamespacesFromPrefixing = array();

    /** @var string[]  */
    protected array $excludeFilePatternsFromPrefixing = array();

    /** @var string[]  */
    protected array $namespaceReplacementPatterns = array();

    /** @var string[] */
    protected array $discoveredNamespaces = [];

    /** @var string[] */
    protected array $discoveredClasses = [];

    /** @var string[] */
    protected array $discoveredConstants = [];

    /**
     * ChangeEnumerator constructor.
     * @param \BrianHenryIE\Strauss\Composer\Extra\StraussConfig $config
     */
    public function __construct(StraussConfig $config)
    {
        $this->namespacePrefix = $config->getNamespacePrefix();
        $this->classmapPrefix = $config->getClassmapPrefix();

        $this->excludePackagesFromPrefixing = $config->getExcludePackagesFromPrefixing();
        $this->excludeNamespacesFromPrefixing = $config->getExcludeNamespacesFromPrefixing();
        $this->excludeFilePatternsFromPrefixing = $config->getExcludeFilePatternsFromPrefixing();

        $this->namespaceReplacementPatterns = $config->getNamespaceReplacementPatterns();
    }

    /**
     * TODO: Order by longest string first. (or instead, record classnames with their namespaces)
     *
     * @return string[]
     */
    public function getDiscoveredNamespaces(?string $namespacePrefix = ''): array
    {
        $discoveredNamespaceReplacements = [];

        // When running subsequent times, try to discover the original namespaces.
        // This is naive: it will not work where namespace replacement patterns have been used.
        foreach ($this->discoveredNamespaces as $key => $value) {
            $unprefixed = str_starts_with($key, $this->namespacePrefix)
                ? ltrim(substr($key, strlen($this->namespacePrefix)), '\\')
                : $key;
            $discoveredNamespaceReplacements[ $unprefixed ] = $value;
        }

        uksort($discoveredNamespaceReplacements, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        return $discoveredNamespaceReplacements;
    }

    /**
     * @return string[]
     */
    public function getDiscoveredClasses(?string $classmapPrefix = ''): array
    {
        unset($this->discoveredClasses['ReturnTypeWillChange']);
        foreach ($this->getBuiltInClassnames() as $builtInClass) {
            unset($this->discoveredClasses[$builtInClass]);
        }

        $discoveredClasses = array_filter(
            array_keys($this->discoveredClasses),
            function (string $replacement) use ($classmapPrefix) {
                return empty($classmapPrefix) || ! str_starts_with($replacement, $classmapPrefix);
            }
        );

        return $discoveredClasses;
    }

    /**
     * @return string[]
     */
    public function getDiscoveredConstants(?string $constantsPrefix = ''): array
    {
        $discoveredConstants = array_filter(
            array_keys($this->discoveredConstants),
            function (string $replacement) use ($constantsPrefix) {
                return empty($constantsPrefix) || ! str_starts_with($replacement, $constantsPrefix);
            }
        );

        return $discoveredConstants;
    }

    /**
     * @param string $absoluteTargetDir
     * @param array<string,array{dependency:ComposerPackage,sourceAbsoluteFilepath:string,targetRelativeFilepath:string}> $filesArray
     */
    public function findInFiles($absoluteTargetDir, $filesArray): void
    {
        foreach ($filesArray as $relativeFilepath => $fileArray) {
            $package = $fileArray['dependency'];
            foreach ($this->excludePackagesFromPrefixing as $excludePackagesName) {
                if ($package->getPackageName() === $excludePackagesName) {
                    continue 2;
                }
            }

            foreach ($this->excludeFilePatternsFromPrefixing as $excludeFilePattern) {
                if (1 === preg_match($excludeFilePattern, $relativeFilepath)) {
                    continue 2;
                }
            }

            $filepath = $absoluteTargetDir . $relativeFilepath;

            // TODO: use flysystem
            // $contents = $this->filesystem->read($targetFile);

            $contents = file_get_contents($filepath);
            if (false === $contents) {
                throw new \Exception("Failed to read file at {$filepath}");
            }

            $this->find($contents);
        }
    }


    /**
     * TODO: Don't use preg_replace_callback!
     *
     * @param string $contents
     *
     * @return string $contents
     */
    public function find(string $contents): string
    {

        // If the entire file is under one namespace, all we want is the namespace.
        // If there were more than one namespace, it would appear as `namespace MyNamespace { ...`,
        // a file with only a single namespace will appear as `namespace MyNamespace;`.
        $singleNamespacePattern = '/
            (<?php|\r\n|\n)                                              # A new line or the beginning of the file.
            \s*                                                          # Allow whitespace before
            namespace\s+(?<namespace>[0-9A-Za-z_\x7f-\xff\\\\]+)[\s\S]*; # Match a single namespace in the file.
        /x'; //  # x: ignore whitespace in regex.
        if (1 === preg_match($singleNamespacePattern, $contents, $matches)) {
            $this->addDiscoveredNamespaceChange($matches['namespace']);
            return $contents;
        }

        if (0 < preg_match_all('/\s*define\s*\(\s*["\']([^"\']*)["\']\s*,\s*["\'][^"\']*["\']\s*\)\s*;/', $contents, $constants)) {
            foreach ($constants[1] as $constant) {
                $this->discoveredConstants[$constant] = $constant;
            }
        }

        // TODO traits

        // TODO: Is the ";" in this still correct since it's being taken care of in the regex just above?
        // Looks like with the preceding regex, it will never match.


        return preg_replace_callback(
            '
			~											# Start the pattern
				[\r\n]+\s*namespace\s+([a-zA-Z0-9_\x7f-\xff\\\\]+)[;{\s\n]{1}[\s\S]*?(?=namespace|$) 
														# Look for a preceding namespace declaration, 
														# followed by a semicolon, open curly bracket, space or new line
														# up until a 
														# potential second namespace declaration or end of file.
														# if found, match that much before continuing the search on
				|										# the remainder of the string.
				\/\*[\s\S]*?\*\/ |                      # Skip multiline comments
				^\s*\/\/.*$	|   						# Skip single line comments
				\s*										# Whitespace is allowed before 
				(?:abstract\sclass|class|interface)\s+	# Look behind for class, abstract class, interface
				([a-zA-Z0-9_\x7f-\xff]+)				# Match the word until the first non-classname-valid character
				\s?										# Allow a space after
				(?:{|extends|implements|\n|$)			# Class declaration can be followed by {, extends, implements 
														# or a new line
			~x', //                                     # x: ignore whitespace in regex.
            function ($matches) {

                // If we're inside a namespace other than the global namespace:
                if (1 === preg_match('/^\s*namespace\s+[a-zA-Z0-9_\x7f-\xff\\\\]+[;{\s\n]{1}.*/', $matches[0])) {
                    $this->addDiscoveredNamespaceChange($matches[1]);

                    return $matches[0];
                }

                if (count($matches) < 3) {
                    return $matches[0];
                }

                // TODO: Why is this [2] and not [1] (which seems to be always empty).
                $this->discoveredClasses[$matches[2]] = $matches[2];
                return $matches[0];
            },
            $contents
        );
    }

    protected function addDiscoveredNamespaceChange(string $namespace): void
    {

        foreach ($this->excludeNamespacesFromPrefixing as $excludeNamespace) {
            if (0 === strpos($namespace, $excludeNamespace)) {
                return;
            }
        }

        foreach ($this->namespaceReplacementPatterns as $namespaceReplacementPattern => $replacement) {
            $prefixed = preg_replace($namespaceReplacementPattern, $replacement, $namespace);

            if ($prefixed !== $namespace) {
                $this->discoveredNamespaces[$namespace] = $prefixed;
                return;
            }
        }

        $this->discoveredNamespaces[$namespace] = $this->namespacePrefix . '\\'. $namespace;
    }

    /**
     * Get a list of PHP built-in classes so they are not prefixed.
     *
     * Polyfilled classes were being prefixed, but the polyfills are only active when the PHP version is below X,
     * so calls to those prefixed polyfilled classnames would fail on newer PHP versions.
     *
     * NB: This list is not exhaustive. Any unloaded PHP extensions are not included.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/79
     *
     * ```
     * array_filter(
     *   get_declared_classes(),
     *   function(string $className): bool {
     *     $reflector = new \ReflectionClass($className);
     *     return empty($reflector->getFileName());
     *   }
     * );
     * ```
     *
     * @return string[]
     */
    protected function getBuiltInClassnames(): array
    {
        return array_merge(
            ...array_values(
                [
                    '7.4' => [
                        'AppendIterator',
                        'ArgumentCountError',
                        'ArithmeticError',
                        'ArrayIterator',
                        'ArrayObject',
                        'AssertionError',
                        'BadFunctionCallException',
                        'BadMethodCallException',
                        'CURLFile',
                        'CachingIterator',
                        'CallbackFilterIterator',
                        'ClosedGeneratorException',
                        'Closure',
                        'Collator',
                        'CompileError',
                        'DOMAttr',
                        'DOMCdataSection',
                        'DOMCharacterData',
                        'DOMComment',
                        'DOMConfiguration',
                        'DOMDocument',
                        'DOMDocumentFragment',
                        'DOMDocumentType',
                        'DOMDomError',
                        'DOMElement',
                        'DOMEntity',
                        'DOMEntityReference',
                        'DOMErrorHandler',
                        'DOMException',
                        'DOMImplementation',
                        'DOMImplementationList',
                        'DOMImplementationSource',
                        'DOMLocator',
                        'DOMNameList',
                        'DOMNameSpaceNode',
                        'DOMNamedNodeMap',
                        'DOMNode',
                        'DOMNodeList',
                        'DOMNotation',
                        'DOMProcessingInstruction',
                        'DOMStringExtend',
                        'DOMStringList',
                        'DOMText',
                        'DOMTypeinfo',
                        'DOMUserDataHandler',
                        'DOMXPath',
                        'DateInterval',
                        'DatePeriod',
                        'DateTime',
                        'DateTimeImmutable',
                        'DateTimeZone',
                        'Directory',
                        'DirectoryIterator',
                        'DivisionByZeroError',
                        'DomainException',
                        'EmptyIterator',
                        'Error',
                        'ErrorException',
                        'Exception',
                        'FFI',
                        'FFI\CData',
                        'FFI\CType',
                        'FFI\Exception',
                        'FFI\ParserException',
                        'FilesystemIterator',
                        'FilterIterator',
                        'GMP',
                        'Generator',
                        'GlobIterator',
                        'HashContext',
                        'InfiniteIterator',
                        'IntlBreakIterator',
                        'IntlCalendar',
                        'IntlChar',
                        'IntlCodePointBreakIterator',
                        'IntlDateFormatter',
                        'IntlException',
                        'IntlGregorianCalendar',
                        'IntlIterator',
                        'IntlPartsIterator',
                        'IntlRuleBasedBreakIterator',
                        'IntlTimeZone',
                        'InvalidArgumentException',
                        'IteratorIterator',
                        'JsonException',
                        'LengthException',
                        'LibXMLError',
                        'LimitIterator',
                        'Locale',
                        'LogicException',
                        'MessageFormatter',
                        'MultipleIterator',
                        'NoRewindIterator',
                        'Normalizer',
                        'NumberFormatter',
                        'OutOfBoundsException',
                        'OutOfRangeException',
                        'OverflowException',
                        'PDO',
                        'PDOException',
                        'PDORow',
                        'PDOStatement',
                        'ParentIterator',
                        'ParseError',
                        'Phar',
                        'PharData',
                        'PharException',
                        'PharFileInfo',
                        'RangeException',
                        'RecursiveArrayIterator',
                        'RecursiveCachingIterator',
                        'RecursiveCallbackFilterIterator',
                        'RecursiveDirectoryIterator',
                        'RecursiveFilterIterator',
                        'RecursiveIteratorIterator',
                        'RecursiveRegexIterator',
                        'RecursiveTreeIterator',
                        'Reflection',
                        'ReflectionClass',
                        'ReflectionClassConstant',
                        'ReflectionException',
                        'ReflectionExtension',
                        'ReflectionFunction',
                        'ReflectionFunctionAbstract',
                        'ReflectionGenerator',
                        'ReflectionMethod',
                        'ReflectionNamedType',
                        'ReflectionObject',
                        'ReflectionParameter',
                        'ReflectionProperty',
                        'ReflectionReference',
                        'ReflectionType',
                        'ReflectionZendExtension',
                        'RegexIterator',
                        'ResourceBundle',
                        'RuntimeException',
                        'SQLite3',
                        'SQLite3Result',
                        'SQLite3Stmt',
                        'SessionHandler',
                        'SimpleXMLElement',
                        'SimpleXMLIterator',
                        'SoapClient',
                        'SoapFault',
                        'SoapHeader',
                        'SoapParam',
                        'SoapServer',
                        'SoapVar',
                        'SodiumException',
                        'SplDoublyLinkedList',
                        'SplFileInfo',
                        'SplFileObject',
                        'SplFixedArray',
                        'SplHeap',
                        'SplMaxHeap',
                        'SplMinHeap',
                        'SplObjectStorage',
                        'SplPriorityQueue',
                        'SplQueue',
                        'SplStack',
                        'SplTempFileObject',
                        'Spoofchecker',
                        'Transliterator',
                        'TypeError',
                        'UConverter',
                        'UnderflowException',
                        'UnexpectedValueException',
                        'WeakReference',
                        'XMLReader',
                        'XMLWriter',
                        'XSLTProcessor',
                        'ZipArchive',
                        '__PHP_Incomplete_Class',
                        'finfo',
                        'mysqli',
                        'mysqli_driver',
                        'mysqli_result',
                        'mysqli_sql_exception',
                        'mysqli_stmt',
                        'mysqli_warning',
                        'php_user_filter',
                        'stdClass',
                        'tidy',
                        'tidyNode',
                    ],
                    '8.0' => [
                        'AddressInfo',
                        'Attribute',
                        'CurlHandle',
                        'CurlMultiHandle',
                        'CurlShareHandle',
                        'DeflateContext',
                        'GdImage',
                        'InflateContext',
                        'InternalIterator',
                        'OpenSSLAsymmetricKey',
                        'OpenSSLCertificate',
                        'OpenSSLCertificateSigningRequest',
                        'PhpToken',
                        'ReflectionAttribute',
                        'ReflectionUnionType',
                        'Shmop',
                        'Socket',
                        'SysvMessageQueue',
                        'SysvSemaphore',
                        'SysvSharedMemory',
                        'UnhandledMatchError',
                        'ValueError',
                        'WeakMap',
                        'XMLParser',
                    ],
                    '8.1' => [
                        'CURLStringFile',
                        'FTP\Connection',
                        'Fiber',
                        'FiberError',
                        'GdFont',
                        'IntlDatePatternGenerator',
                        'LDAP\Connection',
                        'LDAP\Result',
                        'LDAP\ResultEntry',
                        'PSpell\Config',
                        'PSpell\Dictionary',
                        'PgSql\Connection',
                        'PgSql\Lob',
                        'PgSql\Result',
                        'ReflectionEnum',
                        'ReflectionEnumBackedCase',
                        'ReflectionEnumUnitCase',
                        'ReflectionFiber',
                        'ReflectionIntersectionType',
                        'ReturnTypeWillChange',
                    ],
                    '8.2' => [
                        'AllowDynamicProperties',
                        'Random\BrokenRandomEngineError',
                        'Random\Engine\Mt19937',
                        'Random\Engine\PcgOneseq128XslRr64',
                        'Random\Engine\Secure',
                        'Random\Engine\Xoshiro256StarStar',
                        'Random\RandomError',
                        'Random\RandomException',
                        'Random\Randomizer',
                        'SensitiveParameter',
                        'SensitiveParameterValue',
                    ],
                    '8.3' => [
                    ],
                    '8.4' => [
                        'DOM\Document',
                        'DOM\HTMLDocument',
                        'DOM\XMLDocument',
                        'DateError',
                        'DateException',
                        'DateInvalidOperationException',
                        'DateInvalidTimeZoneException',
                        'DateMalformedIntervalStringException',
                        'DateMalformedPeriodStringException',
                        'DateMalformedStringException',
                        'DateObjectError',
                        'DateRangeError',
                        'Override',
                        'Random\IntervalBoundary',
                        'SQLite3Exception',
                        'dom\attr',
                        'dom\cdatasection',
                        'dom\characterdata',
                        'dom\comment',
                        'dom\documentfragment',
                        'dom\documenttype',
                        'dom\domexception',
                        'dom\element',
                        'dom\entity',
                        'dom\entityreference',
                        'dom\namednodemap',
                        'dom\namespacenode',
                        'dom\node',
                        'dom\nodelist',
                        'dom\notation',
                        'dom\processinginstruction',
                        'dom\text',
                        'dom\xpath',
                    ]
                ]
            )
        );
    }
}
