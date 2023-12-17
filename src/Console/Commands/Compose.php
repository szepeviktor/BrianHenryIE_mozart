<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\ChangeEnumerator;
use BrianHenryIE\Strauss\Autoload;
use BrianHenryIE\Strauss\Cleanup;
use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Copier;
use BrianHenryIE\Strauss\DependenciesEnumerator;
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
    protected DependenciesEnumerator $dependenciesEnumerator;

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

        $this->dependenciesEnumerator = new DependenciesEnumerator(
            $this->workingDir,
            $this->config
        );
        $this->flatDependencyTree = $this->dependenciesEnumerator->getAllDependencies();

        // TODO: Print the dependency tree that Strauss has determined.
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

    protected function writeClassAliasMap(): void
    {
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
            $this->logger->info('Skipping autoloader generation as target directory is vendor directory.');
            return;
        }
        if (isset($this->projectComposerPackage->getAutoload()['classmap'])
            && in_array(
                $this->config->getTargetDirectory(),
                $this->projectComposerPackage->getAutoload()['classmap'],
                true
            )
        ) {
            $this->logger->info('Skipping autoloader generation as target directory is in Composer classmap. Run `composer dump-autoload`.');
            return;
        }

        $this->logger->info('Generating autoloader...');

        $allFilesAutoloaders = $this->dependenciesEnumerator->getAllFilesAutoloaders();
        $filesAutoloaders = array();
        foreach ($allFilesAutoloaders as $packageName => $packageFilesAutoloader) {
            if (in_array($packageName, $this->config->getExcludePackagesFromCopy())) {
                continue;
            }
            $filesAutoloaders[$packageName] = $packageFilesAutoloader;
        }

        $classmap = new Autoload($this->config, $this->workingDir, $filesAutoloaders);

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
