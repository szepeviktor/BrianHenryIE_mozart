<?php
/**
 * Deletes source files and empty directories.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use FilesystemIterator;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Cleanup
{

    /** @var Filesystem */
    protected Filesystem $filesystem;

    protected string $workingDir;

    protected bool $isDeleteVendorFiles;
    protected bool $isDeleteVendorPackages;

    protected string $vendorDirectory = 'vendor'. DIRECTORY_SEPARATOR;
    protected string $targetDirectory;

    public function __construct(StraussConfig $config, string $workingDir)
    {
        $this->vendorDirectory = $config->getVendorDirectory();
        $this->targetDirectory = $config->getTargetDirectory();
        $this->workingDir = $workingDir;

        $this->isDeleteVendorFiles = $config->isDeleteVendorFiles() && $config->getTargetDirectory() !== $config->getVendorDirectory();
        $this->isDeleteVendorPackages = $config->isDeleteVendorPackages() && $config->getTargetDirectory() !== $config->getVendorDirectory();

        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($workingDir));
    }

    /**
     * Maybe delete the source files that were copied (depending on config),
     * then delete empty directories.
     *
     * @param string[] $sourceFiles Relative filepaths.
     */
    public function cleanup(array $sourceFiles): void
    {
        if (!$this->isDeleteVendorPackages && !$this->isDeleteVendorFiles) {
            return;
        }

        if ($this->isDeleteVendorPackages) {
            $package_dirs = array_unique(array_map(function (string $relativeFilePath): string {
                list( $vendor, $package ) = explode('/', $relativeFilePath);
                return "{$vendor}/{$package}";
            }, $sourceFiles));

            foreach ($package_dirs as $package_dir) {
                $relativeDirectoryPath = $this->vendorDirectory . $package_dir;

                $absolutePath = $this->workingDir . $relativeDirectoryPath;

                if (is_link($absolutePath)) {
                    unlink($absolutePath);
                }

                if ($absolutePath !== realpath($absolutePath)) {
                    continue;
                }

                $this->filesystem->deleteDirectory($relativeDirectoryPath);
            }
        } elseif ($this->isDeleteVendorFiles) {
            foreach ($sourceFiles as $sourceFile) {
                $relativeFilepath = $this->vendorDirectory . $sourceFile;

                $absolutePath = $this->workingDir . $relativeFilepath;

                if ($absolutePath !== realpath($absolutePath)) {
                    continue;
                }

                $this->filesystem->delete($relativeFilepath);
            }

            $this->cleanupFilesAutoloader();
        }

        // Get the root folders of the moved files.
        $rootSourceDirectories = [];
        foreach ($sourceFiles as $sourceFile) {
            $arr = explode("/", $sourceFile, 2);
            $dir = $arr[0];
            $rootSourceDirectories[ $dir ] = $dir;
        }
        $rootSourceDirectories = array_map(
            function (string $path): string {
                return $this->vendorDirectory . $path;
            },
            array_keys($rootSourceDirectories)
        );

        foreach ($rootSourceDirectories as $rootSourceDirectory) {
            if (!is_dir($rootSourceDirectory) || is_link($rootSourceDirectory)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->workingDir . $rootSourceDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($it as $file) {
                if ($file->isDir() && $this->dirIsEmpty($file)) {
                    rmdir((string)$file);
                }
            }
        }
    }

    // TODO: Use Symphony or Flysystem functions.
    protected function dirIsEmpty($dir): bool
    {
        $absolutePath = $dir;
        $di = new RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS);
        return iterator_count($di) === 0;
    }

    /**
     * After files are deleted, remove them from the Composer files autoloaders.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/34#issuecomment-922503813
     */
    protected function cleanupFilesAutoloader(): void
    {
        if (! file_exists($this->workingDir . 'vendor/composer/autoload_files.php')) {
            return;
        }

        $files = include $this->workingDir . 'vendor/composer/autoload_files.php';

        $missingFiles = array();

        foreach ($files as $file) {
            if (! file_exists($file)) {
                $missingFiles[] = str_replace([ $this->workingDir, 'vendor/composer/../', 'vendor/' ], '', $file);
                // When `composer install --no-dev` is run, it creates an index of files autoload files which
                // references the non-existant files. This causes a fatal error when the autoloader is included.
                // TODO: if delete_vendor_packages is true, do not create this file.
                $this->filesystem->write(
                    str_replace($this->workingDir, '', $file),
                    '<?php // This file was deleted by {@see https://github.com/BrianHenryIE/strauss}.'
                );
            }
        }

        if (empty($missingFiles)) {
            return;
        }

        $targetDirectory = $this->targetDirectory;

        foreach (array('autoload_static.php', 'autoload_files.php') as $autoloadFile) {
            $autoloadStaticPhp = $this->filesystem->read('vendor/composer/'.$autoloadFile);

            $autoloadStaticPhpAsArray = explode(PHP_EOL, $autoloadStaticPhp);

            $newAutoloadStaticPhpAsArray = array_map(
                function (string $line) use ($missingFiles, $targetDirectory): string {
                    $containsFile = array_reduce(
                        $missingFiles,
                        function (bool $carry, string $filepath) use ($line): bool {
                            return $carry || false !== strpos($line, $filepath);
                        },
                        false
                    );

                    if (!$containsFile) {
                        return $line;
                    }

                    // TODO: Check the file does exist at the new location. It definitely should be.
                    // TODO: If the Strauss autoloader is being created, just return an empty string here.

                    return str_replace([
                        "=> __DIR__ . '/..' . '/",
                        "=> \$vendorDir . '/"
                    ], [
                        "=> __DIR__ . '/../../$targetDirectory' . '/",
                        "=> \$baseDir . '/$targetDirectory"
                    ], $line);
                },
                $autoloadStaticPhpAsArray
            );

            $newAutoloadStaticPhp = implode(PHP_EOL, $newAutoloadStaticPhpAsArray);

            $this->filesystem->write('vendor/composer/'.$autoloadFile, $newAutoloadStaticPhp);
        }
    }
}
