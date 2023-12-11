<?php
/**
 * Build a list of files from the composer autoloaders.
 *
 * Also record the `files` autoloaders.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Symfony\Component\Finder\Finder;

class FileEnumerator
{

    /**
     * The only path variable with a leading slash.
     * All directories in project end with a slash.
     *
     * @var string
     */
    protected string $workingDir;

    /** @var string */
    protected string $vendorDir;

    /** @var ComposerPackage[] */
    protected array $dependencies;

    /** @var string[]  */
    protected array $excludePackageNames = array();

    /** @var string[]  */
    protected array $excludeNamespaces = array();

    /** @var string[]  */
    protected array $excludeFilePatterns = array();

    /** @var Filesystem */
    protected Filesystem $filesystem;

    /**
     * Complete list of files specified in packages autoloaders.
     *
     * @var array<string,array{dependency:ComposerPackage,sourceAbsoluteFilepath:string,targetRelativeFilepath:string}>
     */
    protected array $filesWithDependencies = [];

    /**
     * Record the files autoloaders for later use in building our own autoloader.
     *
     * Package-name: [ dir1, file1, file2, ... ].
     *
     * @var array<string, string[]>
     */
    protected array $filesAutoloaders = [];

    /**
     * Copier constructor.
     * @param ComposerPackage[] $dependencies
     * @param string $workingDir
     */
    public function __construct(
        array $dependencies,
        string $workingDir,
        StraussConfig $config
    ) {
        $this->workingDir = $workingDir;
        $this->vendorDir = $config->getVendorDirectory();

        $this->dependencies = $dependencies;

        $this->excludeNamespaces = $config->getExcludeNamespacesFromCopy();
        $this->excludePackageNames = $config->getExcludePackagesFromCopy();
        $this->excludeFilePatterns = $config->getExcludeFilePatternsFromCopy();

        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->workingDir));
    }

    /**
     * Read the autoload keys of the dependencies and generate a list of the files referenced.
     */
    public function compileFileList(): void
    {

        $prefixToRemove = $this->workingDir . $this->vendorDir;

        foreach ($this->dependencies as $dependency) {
            if (in_array($dependency->getPackageName(), $this->excludePackageNames)) {
                continue;
            }

            $packageAbsolutePath = $dependency->getPackageAbsolutePath();

            /**
             * Where $dependency->autoload is ~
             *
             * [ "psr-4" => [ "BrianHenryIE\Strauss" => "src" ] ]
             * Exclude "exclude-from-classmap"
             * @see https://getcomposer.org/doc/04-schema.md#exclude-files-from-classmaps
             */
            $autoloaders = array_filter($dependency->getAutoload(), function ($type) {
                return 'exclude-from-classmap' !== $type;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($autoloaders as $type => $value) {
                // Might have to switch/case here.

                if ('files' === $type) {
                    $this->filesAutoloaders[$dependency->getRelativePath()] = $value;
                }

                foreach ($value as $namespace => $namespace_relative_paths) {
                    if (!empty($namespace) && in_array($namespace, $this->excludeNamespaces)) {
                        continue;
                    }

                    if (! is_array($namespace_relative_paths)) {
                        $namespace_relative_paths = array( $namespace_relative_paths );
                    }

                    foreach ($namespace_relative_paths as $namespace_relative_path) {
                        $sourceAbsolutePath = $packageAbsolutePath . $namespace_relative_path;
                        $sourceRelativePath = $this->vendorDir . $dependency->getRelativePath() . DIRECTORY_SEPARATOR . $namespace_relative_path;
                        if (is_file($sourceAbsolutePath)) {
                            $outputRelativeFilepath = $dependency->getRelativePath() . DIRECTORY_SEPARATOR . $namespace_relative_path;

                            foreach ($this->excludeFilePatterns as $excludePattern) {
                                if (1 === preg_match($excludePattern, $outputRelativeFilepath)) {
                                    continue 2;
                                }
                            }

                            if ('<?php // This file was deleted by {@see https://github.com/BrianHenryIE/strauss}.'
                                ===
                                $this->filesystem->read($sourceRelativePath)
                            ) {
                                continue;
                            }

                            $file = array(
                                'dependency'             => $dependency,
                                'sourceAbsoluteFilepath' => $sourceAbsolutePath,
                                'targetRelativeFilepath' => $outputRelativeFilepath,
                            );
                            $this->filesWithDependencies[ $outputRelativeFilepath ] = $file;
                            continue;
                        } elseif (is_dir($sourceAbsolutePath)) {
                            // trailingslashit().
                            $namespace_relative_path = rtrim($namespace_relative_path, DIRECTORY_SEPARATOR)
                                                       . DIRECTORY_SEPARATOR;

                            $sourcePath = $packageAbsolutePath . $namespace_relative_path;

                            // trailingslashit(). (to remove duplicates).
                            $sourcePath = rtrim($sourcePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

                            $finder = new Finder();
                            $finder->files()->in($sourcePath)->followLinks();

                            foreach ($finder as $foundFile) {
                                $sourceAbsoluteFilepath = $foundFile->getPathname();
                                $sourceRelativePath = str_replace($this->workingDir, '', $sourceAbsoluteFilepath);
                                $outputRelativeFilepath = str_replace($prefixToRemove, '', $sourceAbsoluteFilepath);

                                // For symlinked packages.
                                if ($outputRelativeFilepath == $sourceAbsoluteFilepath) {
                                    $outputRelativeFilepath = str_replace($packageAbsolutePath, $dependency->getPackageName() . DIRECTORY_SEPARATOR, $sourceAbsoluteFilepath);
                                }

                                // TODO: Is this needed here?! If anything, it's the prefix that needs to be normalised a few
                                // lines above before being used.
                                // Replace multiple \ and/or / with OS native DIRECTORY_SEPARATOR.
                                $outputRelativeFilepath = preg_replace('#[\\\/]+#', DIRECTORY_SEPARATOR, $outputRelativeFilepath);
                                if (is_null($outputRelativeFilepath)) {
                                    throw new \Exception('Error replacing directory separator in outputRelativeFilepath.');
                                }

                                foreach ($this->excludeFilePatterns as $excludePattern) {
                                    if (1 === preg_match($excludePattern, $outputRelativeFilepath)) {
                                        continue 2;
                                    }
                                }

                                if (is_dir($sourceAbsoluteFilepath)) {
                                    continue;
                                }

                                if (!$this->filesystem->fileExists($sourceRelativePath)) {
                                    continue;
                                }

                                if ('<?php // This file was deleted by {@see https://github.com/BrianHenryIE/strauss}.'
                                    ===
                                    $this->filesystem->read($sourceRelativePath)
                                ) {
                                    continue;
                                }

                                $file                                                   = array(
                                    'dependency'             => $dependency,
                                    'sourceAbsoluteFilepath' => $sourceAbsoluteFilepath,
                                    'targetRelativeFilepath' => $outputRelativeFilepath,
                                );
                                $this->filesWithDependencies[ $outputRelativeFilepath ] = $file;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns all found files.
     *
     * @return array<string,array{dependency:ComposerPackage,sourceAbsoluteFilepath:string,targetRelativeFilepath:string}>
     */
    public function getAllFilesAndDependencyList(): array
    {
        return $this->filesWithDependencies;
    }

    /**
     * Returns found PHP files.
     *
     * @return array<string,array{dependency:ComposerPackage,sourceAbsoluteFilepath:string,targetRelativeFilepath:string}>
     */
    public function getPhpFilesAndDependencyList(): array
    {
        // Filter out non .php files by checking the key.
        return array_filter($this->filesWithDependencies, function ($value, $key) {
            return false !== strpos($key, '.php');
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Get the recorded files autoloaders.
     *
     * @return array<string, array<string>>
     */
    public function getFilesAutoloaders(): array
    {
        return $this->filesAutoloaders;
    }

    /**
     * @param string $workingDir Absolute path to the working directory, results will be relative to this.
     * @param string $relativeDirectory
     * @param string $regexPattern Default to PHP files.
     *
     * @return string[]
     */
    public function findFilesInDirectory(string $workingDir, string $relativeDirectory = '.', string $regexPattern = '/.+\.php$/'): array
    {
        $dir = new RecursiveDirectoryIterator($workingDir . $relativeDirectory);
        $ite = new RecursiveIteratorIterator($dir);
        $files = new RegexIterator($ite, $regexPattern, RegexIterator::GET_MATCH);
        $fileList = array();
        foreach ($files as $file) {
            $fileList = array_merge($fileList, str_replace($workingDir, '', $file));
        }
        return $fileList;
    }
}
