<?php
/**
 * Extends ComposerPackage to return the typed Strauss config.
 */

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use Composer\Factory;
use Composer\IO\NullIO;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputInterface;

class ProjectComposerPackage extends ComposerPackage
{
    protected string $author;

    protected string $vendorDirectory;

    /**
     * @param string $absolutePath
     * @param ?array{files?:array<string>,classmap?:array<string>,"psr-4"?:array<string,string|array<string>>} $overrideAutoload
     */
    public function __construct(string $absolutePath, ?array $overrideAutoload = null)
    {
        if (is_dir($absolutePath)) {
            $absolutePathDir = $absolutePath;
            $absolutePathFile = rtrim($absolutePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        } else {
            $absolutePathDir = rtrim($absolutePath, 'composer.json');
            $absolutePathFile = $absolutePath;
        }
        unset($absolutePath);

        $composer = Factory::create(new NullIO(), $absolutePathFile, true);

        parent::__construct($composer, $overrideAutoload);

        $authors = $this->composer->getPackage()->getAuthors();
        if (empty($authors) || !isset($authors[0]['name'])) {
            $this->author = explode("/", $this->packageName, 2)[0];
        } else {
            $this->author = $authors[0]['name'];
        }

        $vendorDirectory = $this->composer->getConfig()->get('vendor-dir');
        if (is_string($vendorDirectory)) {
            $vendorDirectory = str_replace($absolutePathDir, '', (string) $vendorDirectory);
            $this->vendorDirectory = $vendorDirectory;
        } else {
            $this->vendorDirectory = 'vendor' . DIRECTORY_SEPARATOR;
        }
    }

    /**
     * @return StraussConfig
     * @throws \Exception
     */
    public function getStraussConfig(InputInterface $input): StraussConfig
    {
        $config = new StraussConfig($this->composer, $input);
        $config->setVendorDirectory($this->getVendorDirectory());
        return $config;
    }


    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getVendorDirectory(): string
    {
        return rtrim($this->vendorDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Get all values in the autoload key as a flattened array.
     *
     * @return string[]
     */
    public function getFlatAutoloadKey(): array
    {
        $autoload = $this->getAutoload();
        $values = [];
        array_walk_recursive(
            $autoload,
            function ($value, $key) use (&$values) {
                $values[] = $value;
            }
        );
        return $values;
    }
}
