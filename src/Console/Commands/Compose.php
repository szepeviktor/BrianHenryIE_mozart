<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\ChangeEnumerator;
use BrianHenryIE\Strauss\Autoload;
use BrianHenryIE\Strauss\Cleanup;
use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Copier;
use BrianHenryIE\Strauss\FileEnumerator;
use BrianHenryIE\Strauss\Licenser;
use BrianHenryIE\Strauss\Prefixer;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class Compose extends Command
{
    use LoggerAwareTrait;

    /** @var string */
    protected string $workingDir;

    /** @var StraussConfig */
    protected StraussConfig $config;

    protected ProjectComposerPackage $projectComposerPackage;

    /** @var Copier */
    protected Copier $copier;

    /** @var Prefixer */
    protected Prefixer $replacer;
    /**
     * @var ChangeEnumerator
     */
    protected ChangeEnumerator $changeEnumerator;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('compose');
        $this->setDescription("Copy composer's `require` and prefix their namespace and classnames.");
        $this->setHelp('');

        $this->addOption(
            'updateCallSites',
            null,
            InputArgument::OPTIONAL,
            'Should replacements also be performed in project files? true|list,of,paths|false'
        );
    }

    /**
     * @see Command::execute()
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setLogger(
            new ConsoleLogger(
                $output,
                [LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL]
            )
        );

        $workingDir = getcwd() . DIRECTORY_SEPARATOR;
        $this->workingDir = $workingDir;

        try {
            $this->loadProjectComposerPackage($input);

            $this->buildDependencyList();

            $this->enumerateFiles();

            $this->copyFiles();

            $this->determineChanges();

            $this->performReplacements();

            $this->performReplacementsInComposerFiles();

            $this->performReplacementsInProjectFiles();

            $this->addLicenses();

            $this->generateAutoloader();

            $this->cleanUp();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            return 1;
        }

        return Command::SUCCESS;
    }


    /**
     * 1. Load the composer.json.
     *
     * @throws Exception
     */
    protected function loadProjectComposerPackage(InputInterface $input): void
    {
        $this->logger->info('Loading config...');

        $this->projectComposerPackage = new ProjectComposerPackage($this->workingDir);

        $config = $this->projectComposerPackage->getStraussConfig($input);

        $this->config = $config;

        // TODO: Print the config that Strauss is using.
        // Maybe even highlight what is default config and what is custom config.
    }

    /** @var ComposerPackage[] */
    protected array $flatDependencyTree = [];

    /**
     * 2. Built flat list of packages and dependencies.
     *
     * 2.1 Initiate getting dependencies for the project composer.json.
     *
     * @see Compose::flatDependencyTree
     */
    protected function buildDependencyList(): void
    {
        $this->logger->info('Building dependency list...');

        $requiredPackageNames = $this->config->getPackages();

        $this->recursiveGetAllDependencies($requiredPackageNames);

        // TODO: Print the dependency tree that Strauss has determined.
    }

    /** @var string[]  */
    protected $virtualPackages = array(
        'php-http/client-implementation'
    );

    /**
     * @param string[] $requiredPackageNames
     */
    protected function recursiveGetAllDependencies(array $requiredPackageNames): void
    {
        $virtualPackages = $this->virtualPackages;

        // Unset PHP, ext-*, ...
        // TODO: I think this code is unnecessary due to how the path to packages is handled (null is fine) later.
        $removePhpExt = function (string $element) use ($virtualPackages) {
            return !(
                0 === strpos($element, 'ext')
                || 'php' === $element
                || in_array($element, $virtualPackages)
            );
        };
        $requiredPackageNames = array_filter($requiredPackageNames, $removePhpExt);

        foreach ($requiredPackageNames as $requiredPackageName) {
            $packageComposerFile = $this->workingDir . $this->config->getVendorDirectory()
                                   . $requiredPackageName . DIRECTORY_SEPARATOR . 'composer.json';

            $overrideAutoload = $this->config->getOverrideAutoload()[ $requiredPackageName ] ?? null;

            if (file_exists($packageComposerFile)) {
                $requiredComposerPackage = ComposerPackage::fromFile($packageComposerFile, $overrideAutoload);
            } else {
                $fileContents           = file_get_contents($this->workingDir . 'composer.lock');
                if (false === $fileContents) {
                    throw new Exception('Failed to read contents of ' . $this->workingDir . 'composer.lock');
                }
                $composerLock           = json_decode($fileContents, true);
                $requiredPackageComposerJson = null;
                foreach ($composerLock['packages'] as $packageJson) {
                    if ($requiredPackageName === $packageJson['name']) {
                        $requiredPackageComposerJson = $packageJson;
                        break;
                    }
                }
                if (is_null($requiredPackageComposerJson)) {
                    // e.g. composer-plugin-api.
                    continue;
                }

                $requiredComposerPackage = ComposerPackage::fromComposerJsonArray($requiredPackageComposerJson, $overrideAutoload);
            }

            if (isset($this->flatDependencyTree[$requiredComposerPackage->getPackageName()])) {
                continue;
            }

            $this->flatDependencyTree[$requiredComposerPackage->getPackageName()] = $requiredComposerPackage;
            $nextRequiredPackageNames                                             = $requiredComposerPackage->getRequiresNames();

            $this->recursiveGetAllDependencies($nextRequiredPackageNames);
        }
    }

    protected FileEnumerator $fileEnumerator;

    protected function enumerateFiles(): void
    {
        $this->logger->info('Enumerating files...');

        $this->fileEnumerator = new FileEnumerator(
            $this->flatDependencyTree,
            $this->workingDir,
            $this->config
        );

        $this->fileEnumerator->compileFileList();
    }

    // 3. Copy autoloaded files for each
    protected function copyFiles(): void
    {
        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            // Nothing to do.
            return;
        }

        $this->logger->info('Copying files...');

        $this->copier = new Copier(
            $this->fileEnumerator->getAllFilesAndDependencyList(),
            $this->workingDir,
            $this->config->getTargetDirectory(),
            $this->config->getVendorDirectory()
        );

        $this->copier->prepareTarget();

        $this->copier->copy();
    }

    // 4. Determine namespace and classname changes
    protected function determineChanges(): void
    {
        $this->logger->info('Determining changes...');

        $this->changeEnumerator = new ChangeEnumerator($this->config);

        $absoluteTargetDir = $this->workingDir . $this->config->getTargetDirectory();
        $phpFiles = $this->fileEnumerator->getPhpFilesAndDependencyList();
        $this->changeEnumerator->findInFiles($absoluteTargetDir, $phpFiles);
    }

    // 5. Update namespaces and class names.
    // Replace references to updated namespaces and classnames throughout the dependencies.
    protected function performReplacements(): void
    {
        $this->logger->info('Performing replacements...');

        $this->replacer = new Prefixer($this->config, $this->workingDir);

        $namespaces = $this->changeEnumerator->getDiscoveredNamespaces($this->config->getNamespacePrefix());
        $classes = $this->changeEnumerator->getDiscoveredClasses($this->config->getClassmapPrefix());
        $constants = $this->changeEnumerator->getDiscoveredConstants($this->config->getConstantsPrefix());
        
        $phpFiles = $this->fileEnumerator->getPhpFilesAndDependencyList();

        $this->replacer->replaceInFiles($namespaces, $classes, $constants, $phpFiles);
    }

    protected function performReplacementsInComposerFiles(): void
    {
        if ($this->config->getTargetDirectory() !== $this->config->getVendorDirectory()) {
            // Nothing to do.
            return;
        }

        $projectReplace = new Prefixer($this->config, $this->workingDir);

        $namespaces = $this->changeEnumerator->getDiscoveredNamespaces($this->config->getNamespacePrefix());
        $classes = $this->changeEnumerator->getDiscoveredClasses($this->config->getClassmapPrefix());
        $constants = $this->changeEnumerator->getDiscoveredConstants($this->config->getConstantsPrefix());

        $composerPhpFileRelativePaths = $this->fileEnumerator->findFilesInDirectory(
            $this->workingDir,
            $this->config->getVendorDirectory() . 'composer'
        );

        $projectReplace->replaceInProjectFiles($namespaces, $classes, $constants, $composerPhpFileRelativePaths);
    }

    protected function performReplacementsInProjectFiles(): void
    {

        $callSitePaths =
            $this->config->getUpdateCallSites()
            ?? $this->projectComposerPackage->getFlatAutoloadKey();

        if (empty($callSitePaths)) {
            return;
        }

        $projectReplace = new Prefixer($this->config, $this->workingDir);

        $namespaces = $this->changeEnumerator->getDiscoveredNamespaces($this->config->getNamespacePrefix());
        $classes = $this->changeEnumerator->getDiscoveredClasses($this->config->getClassmapPrefix());
        $constants = $this->changeEnumerator->getDiscoveredConstants($this->config->getConstantsPrefix());

        $phpFilesRelativePaths = [];
        foreach ($callSitePaths as $relativePath) {
            $absolutePath = $this->workingDir . $relativePath;
            if (is_dir($absolutePath)) {
                $phpFilesRelativePaths = array_merge($phpFilesRelativePaths, $this->fileEnumerator->findFilesInDirectory($this->workingDir, $relativePath));
            } elseif (is_readable($absolutePath)) {
                $phpFilesRelativePaths[] = $relativePath;
            } else {
                $this->logger->warning('Expected file not found from project autoload: ' . $absolutePath);
            }
        }

        $projectReplace->replaceInProjectFiles($namespaces, $classes, $constants, $phpFilesRelativePaths);
    }

    protected function addLicenses(): void
    {
        $this->logger->info('Adding licenses...');

        $author = $this->projectComposerPackage->getAuthor();

        $dependencies = $this->flatDependencyTree;

        $licenser = new Licenser($this->config, $this->workingDir, $dependencies, $author);

        $licenser->copyLicenses();

        $modifiedFiles = $this->replacer->getModifiedFiles();
        $licenser->addInformationToUpdatedFiles($modifiedFiles);
    }

    /**
     * 6. Generate autoloader.
     */
    protected function generateAutoloader(): void
    {
        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            // Nothing to do.
            return;
        }

        $this->logger->info('Generating autoloader...');

        $files = $this->fileEnumerator->getFilesAutoloaders();

        $classmap = new Autoload($this->config, $this->workingDir, $files);

        $classmap->generate();
    }

    /**
     * When namespaces are prefixed which are used by by require and require-dev dependencies,
     * the require-dev dependencies need class aliases specified to point to the new class names/namespaces.
     */
    protected function generateClassAliasList(): void
    {
    }

    /**
     * 7.
     * Delete source files if desired.
     * Delete empty directories in destination.
     */
    protected function cleanUp(): void
    {
        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            // Nothing to do.
            return;
        }

        $this->logger->info('Cleaning up...');

        $cleanup = new Cleanup($this->config, $this->workingDir);

        $sourceFiles = array_keys($this->fileEnumerator->getAllFilesAndDependencyList());

        // TODO: For files autoloaders, delete the contents of the file, not the file itself.

        // This will check the config to check should it delete or not.
        $cleanup->cleanup($sourceFiles);
    }
}
