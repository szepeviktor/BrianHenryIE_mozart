<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use Exception;
use League\Flysystem\Adapter\Local;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;

class Prefixer
{
    /** @var StraussConfig */
    protected $config;

    /** @var Filesystem */
    protected $filesystem;

    protected string $targetDirectory;
    protected string $namespacePrefix;
    protected string $classmapPrefix;
    protected ?string $constantsPrefix;

    protected array $excludePackageNamesFromPrefixing;
    protected array $excludeNamespacesFromPrefixing;
    protected array $excludeFilePatternsFromPrefixing;

    /** @var array<string, ComposerPackage> */
    protected array $changedFiles = array();

    public function __construct(StraussConfig $config, string $workingDir)
    {
        $this->filesystem = new Filesystem(new Local($workingDir));

        $this->targetDirectory = $config->getTargetDirectory();
        $this->namespacePrefix = $config->getNamespacePrefix();
        $this->classmapPrefix = $config->getClassmapPrefix();
        $this->constantsPrefix = $config->getConstantsPrefix();

        $this->excludePackageNamesFromPrefixing = $config->getExcludePackagesFromPrefixing();
        $this->excludeNamespacesFromPrefixing = $config->getExcludeNamespacesFromPrefixing();
        $this->excludeFilePatternsFromPrefixing = $config->getExcludeFilePatternsFromPrefixing();
    }

    // Don't replace a classname if there's an import for a class with the same name.
    // but do replace \Classname always


    /**
     * @param array<string, string> $namespaceChanges
     * @param array<string, string> $classChanges
     * @param array<string,array{dependency:ComposerPackage,sourceAbsoluteFilepath:string,targetRelativeFilepath:string}> $phpFileArrays
     * @throws FileNotFoundException
     */
    public function replaceInFiles(array $namespaceChanges, array $classChanges, array $constants, array $phpFileArrays)
    {

        foreach ($phpFileArrays as $targetRelativeFilepath => $fileArray) {
            $package = $fileArray['dependency'];

            // Skip excluded namespaces.
            if (in_array($package->getPackageName(), $this->excludePackageNamesFromPrefixing)) {
                continue;
            }

            // Skip files whose filepath matches an excluded pattern.
            foreach ($this->excludeFilePatternsFromPrefixing as $excludePattern) {
                if (1 === preg_match($excludePattern, $targetRelativeFilepath)) {
                    continue 2;
                }
            }

            $targetRelativeFilepathFromProject = $this->targetDirectory. $targetRelativeFilepath;

            // Throws an exception, but unlikely to happen.
            $contents = $this->filesystem->read($targetRelativeFilepathFromProject);

            $updatedContents = $this->replaceInString($namespaceChanges, $classChanges, $constants, $contents);

            if ($updatedContents !== $contents) {
                $this->changedFiles[$targetRelativeFilepath] = $package;
                $this->filesystem->put($targetRelativeFilepathFromProject, $updatedContents);
            }
        }
    }

    public function replaceInString(array $namespacesChanges, array $classes, array $originalConstants, string $contents): string
    {

        foreach ($classes as $originalClassname) {
            if ('ReturnTypeWillChange' === $originalClassname) {
                continue;
            }

            $classmapPrefix = $this->classmapPrefix;

            $contents = $this->replaceClassname($contents, $originalClassname, $classmapPrefix);
        }

        // Reorder this so substrings are always ahead of what they might be substrings of.
        asort($namespacesChanges);
        foreach ($namespacesChanges as $originalNamespace => $replacement) {
            if (in_array($originalNamespace, $this->excludeNamespacesFromPrefixing)) {
                continue;
            }

            $contents = $this->replaceNamespace($contents, $originalNamespace, $replacement);
        }

        if (!is_null($this->constantsPrefix)) {
            $contents = $this->replaceConstants($contents, $originalConstants, $this->constantsPrefix);
        }

        return $contents;
    }

    /**
     * TODO: Test against traits.
     *
     * @param string $contents The text to make replacements in.
     *
     * @return string The updated text.
     */
    public function replaceNamespace($contents, $originalNamespace, $replacement)
    {

        $searchNamespace = '\\'.rtrim($originalNamespace, '\\') . '\\';
        $searchNamespace = str_replace('\\\\', '\\', $searchNamespace);
        $searchNamespace = str_replace('\\', '\\\\{0,2}', $searchNamespace);

        $pattern = "
            /                              # Start the pattern
            (
            ^\s*                           # start of the string
            |\\n\s*                        # start of the line
            |(^\s*namespace|[\r\n]+\s*namespace)\s+                  # the namespace keyword
            |use\s+                        # the use keyword
            |use\s+function\s+			   # the use function syntax
            |new\s+
            |static\s+
            |\"                            # inside a string that does not contain spaces - needs work
            |'                             #   right now its just inside a string that doesnt start with a space
            |implements\s+
            |extends\s+                    # when the class being extended is namespaced inline
            |return\s+
            |\(\s*                         # inside a function declaration as the first parameters type
            |,\s*                          # inside a function declaration as a subsequent parameter type
            |\.\s*                         # as part of a concatenated string
            |=\s*                          # as the value being assigned to a variable
            |\*\s+@\w+\s*                  # In a comments param etc  
            |&\s*                             # a static call as a second parameter of an if statement
            |\|\s*
            |!\s*                             # negating the result of a static call
            |=>\s*                            # as the value in an associative array
            |\[\s*                         # In a square array 
            |\?\s*                         # In a ternary operator
            |:\s*                          # In a ternary operator
            )
            (
            {$searchNamespace}             # followed by the namespace to replace
            )
            (?!:)                          # Not followed by : which would only be valid after a classname
            (
            \s*;                           # followed by a semicolon 
            |\\\\{1,2}[a-zA-Z0-9_\x7f-\xff]+         # or a classname no slashes 
            |\s+as                         # or the keyword as 
            |\"                            # or quotes
            |'                             # or single quote         
            |:                             # or a colon to access a static
            |\\\\{
            )                            
            /Ux";                          // U: Non-greedy matching, x: ignore whitespace in pattern.

        $replacingFunction = function ($matches) use ($originalNamespace, $replacement) {
            $singleBackslash = '\\';
            $doubleBackslash = '\\\\';

            if (false !== strpos($matches[3], $doubleBackslash)) {
                $originalNamespace = str_replace($singleBackslash, $doubleBackslash, $originalNamespace);
                $replacement = str_replace($singleBackslash, $doubleBackslash, $replacement);
            }

            $replaced = str_replace($originalNamespace, $replacement, $matches[0]);

            return $replaced;
        };

        $result = preg_replace_callback($pattern, $replacingFunction, $contents);

        $matchingError = preg_last_error();
        if (0 !== $matchingError) {
            $message = "Matching error {$matchingError}";
            if (PREG_BACKTRACK_LIMIT_ERROR === $matchingError) {
                $message = 'Preg Backtrack limit was exhausted!';
            }
            throw new Exception($message);
        }

        // For prefixed functions which do not begin with a backslash, add one.
        // I'm not certain this is a good idea.
        // @see https://github.com/BrianHenryIE/strauss/issues/65
        $functionReplacingPatten = '/\\\\?('.preg_quote(ltrim($replacement, '\\'), '/').'\\\\(?:[a-zA-Z0-9_\x7f-\xff]+\\\\)*[a-zA-Z0-9_\x7f-\xff]+\\()/';
        $result = preg_replace(
            $functionReplacingPatten,
            "\\\\$1",
            $result
        );

        return $result;
    }

    /**
     * In a namespace:
     * * use \Classname;
     * * new \Classname()
     *
     * In a global namespace:
     * * new Classname()
     *
     * @param string $contents
     * @param string $originalClassname
     * @param string $classnamePrefix
     * @return array|string|string[]|null
     * @throws \Exception
     */
    public function replaceClassname($contents, $originalClassname, $classnamePrefix)
    {
        $searchClassname = preg_quote($originalClassname, '/');

        // This could be more specific if we could enumerate all preceding and proceeding words ("new", "("...).
        $pattern = '
			/											# Start the pattern
				(^\s*namespace|\r\n\s*namespace)\s+[a-zA-Z0-9_\x7f-\xff\\\\]+\s*{(.*?)(namespace|\z) 
														# Look for a preceding namespace declaration, up until a 
														# potential second namespace declaration.
				|										# if found, match that much before continuing the search on
								    		        	# the remainder of the string.
                (^\s*namespace|\r\n\s*namespace)\s+[a-zA-Z0-9_\x7f-\xff\\\\]+\s*;(.*) # Skip lines just declaring the namespace.
                |		        	
				([^a-zA-Z0-9_\x7f-\xff\$\\\])('. $searchClassname . ')([^a-zA-Z0-9_\x7f-\xff\\\]) # outside a namespace the class will not be prefixed with a slash
				
			/xsm'; //                                    # x: ignore whitespace in regex.  s dot matches newline, m: ^ and $ match start and end of line

        $replacingFunction = function ($matches) use ($originalClassname, $classnamePrefix) {

            // If we're inside a namespace other than the global namespace:
            if (1 === preg_match('/\s*namespace\s+[a-zA-Z0-9_\x7f-\xff\\\\]+[;{\s\n]{1}.*/', $matches[0])) {
                $updated = $this->replaceGlobalClassInsideNamedNamespace(
                    $matches[0],
                    $originalClassname,
                    $classnamePrefix
                );

                return $updated;
            } else {
                $newContents = '';
                foreach ($matches as $index => $captured) {
                    if (0 === $index) {
                        continue;
                    }

                    if ($captured == $originalClassname) {
                        $newContents .= $classnamePrefix;
                    }

                    $newContents .= $captured;
                }
                return $newContents;
            }
//            return $matches[1] . $matches[2] . $matches[3] . $classnamePrefix . $originalClassname . $matches[5];
        };

        $result = preg_replace_callback($pattern, $replacingFunction, $contents);

        $matchingError = preg_last_error();
        if (0 !== $matchingError) {
            $message = "Matching error {$matchingError}";
            if (PREG_BACKTRACK_LIMIT_ERROR === $matchingError) {
                $message = 'Backtrack limit was exhausted!';
            }
            throw new Exception($message);
        }

        return $result;
    }

    /**
     * Pass in a string and look for \Classname instances.
     *
     * @param string $contents
     * @param string $originalClassname
     * @param string $classnamePrefix
     * @return string
     */
    protected function replaceGlobalClassInsideNamedNamespace($contents, $originalClassname, $classnamePrefix): string
    {
        $replacement = $classnamePrefix . $originalClassname;

        // use Prefixed_Class as Class;
        $usePattern = '/
			(\s*use\s+)
			('.$originalClassname.')   # Followed by the classname
			\s*;
			/x'; //                    # x: ignore whitespace in regex.

        $contents = preg_replace_callback(
            $usePattern,
            function ($matches) use ($replacement) {
                return $matches[1] . $replacement . ' as '. $matches[2] . ';';
            },
            $contents
        );

        $bodyPattern =
            '/([^a-zA-Z0-9_\x7f-\xff]  # Not a class character
			\\\)                       # Followed by a backslash to indicate global namespace
			('.$originalClassname.')   # Followed by the classname
			([^\\\;]+)                 # Not a backslash or semicolon which might indicate a namespace
			/x'; //                    # x: ignore whitespace in regex.

        $contents = preg_replace_callback(
            $bodyPattern,
            function ($matches) use ($replacement) {
                return $matches[1] . $replacement . $matches[2];
            },
            $contents
        );

        return $contents;
    }

    protected function replaceConstants($contents, $originalConstants, $prefix): string
    {

        foreach ($originalConstants as $constant) {
            $contents = $this->replaceConstant($contents, $constant, $prefix . $constant);
        }

        return $contents;
    }

    protected function replaceConstant($contents, $originalConstant, $replacementConstant): string
    {
        return str_replace($originalConstant, $replacementConstant, $contents);
    }

    /**
     * @return array<string, ComposerPackage>
     */
    public function getModifiedFiles(): array
    {
        return $this->changedFiles;
    }
}
