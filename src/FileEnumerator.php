<?php
/**
 * Build a list of files from the composer autoloaders.
 *
 * Also record the `files` autoloaders.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
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

    protected array $excludePackageNames = array();
    protected array $excludeNamespaces = array();
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
     * Record the files autolaoders for later use in building our own autoloader.
     *
     * @var array
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

        $this->filesystem = new Filesystem(new Local($this->workingDir));
    }

    /**
     * Read the autoload keys of the dependencies and generate a list of the files referenced.
     */
    public function compileFileList()
    {

        $prefixToRemove = $this->workingDir . $this->vendorDir;

        foreach ($this->dependencies as $dependency) {
            if (in_array($dependency->getPackageName(), $this->excludePackageNames)) {
                continue;
            }

            $packagePath = $dependency->getPackageAbsolutePath();

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
                        if (is_file($packagePath . $namespace_relative_path)) {
                            //  $finder->files()->name($file)->in($source_path);

                            $sourceAbsoluteFilepath = $packagePath . $namespace_relative_path;

                            $outputRelativeFilepath = str_replace($prefixToRemove, '', $sourceAbsoluteFilepath);
                            $outputRelativeFilepath = preg_replace('#[\\\/]+#', DIRECTORY_SEPARATOR, $outputRelativeFilepath);

                            $file                                                   = array(
                                'dependency'             => $dependency,
                                'sourceAbsoluteFilepath' => $sourceAbsoluteFilepath,
                                'targetRelativeFilepath' => $outputRelativeFilepath,
                            );
                            $this->filesWithDependencies[ $outputRelativeFilepath ] = $file;
                            continue;
                        } else {
                            // else it is a directory.

                            // trailingslashit().
                            $namespace_relative_path = rtrim($namespace_relative_path, DIRECTORY_SEPARATOR)
                                                       . DIRECTORY_SEPARATOR;

                            $sourcePath = $packagePath . $namespace_relative_path;

                            // trailingslashit(). (to remove duplicates).
                            $sourcePath = rtrim($sourcePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

                            $finder = new Finder();
                            $finder->files()->in($sourcePath)->followLinks();

                            foreach ($finder as $foundFile) {
                                $sourceAbsoluteFilepath = $foundFile->getPathname();

                                $outputRelativeFilepath = str_replace($prefixToRemove, '', $sourceAbsoluteFilepath);

                                // For symlinked packages.
                                if ($outputRelativeFilepath == $sourceAbsoluteFilepath) {
                                    $outputRelativeFilepath = str_replace($packagePath, $dependency->getPackageName() . DIRECTORY_SEPARATOR, $sourceAbsoluteFilepath);
                                }

                                // TODO: Is this needed here?! If anything, it's the prefix that needs to be normalised a few
                                // lines above before being used.
                                // Replace multiple \ and/or / with OS native DIRECTORY_SEPARATOR.
                                $outputRelativeFilepath = preg_replace('#[\\\/]+#', DIRECTORY_SEPARATOR, $outputRelativeFilepath);

                                foreach ($this->excludeFilePatterns as $excludePattern) {
                                    if (1 === preg_match($excludePattern, $outputRelativeFilepath)) {
                                        continue 2;
                                    }
                                }

                                if (is_dir($sourceAbsoluteFilepath)) {
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
}
