<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Composer;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use Composer\Factory;
use Composer\IO\NullIO;
use PHPUnit\Framework\TestCase;

class ComposerPackageTest extends TestCase
{

    /**
     * A simple test to check the getters all work.
     */
    public function testParseJson()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $this->assertEquals('iio/libmergepdf', $composer->getPackageName());

        $this->assertIsArray($composer->getAutoload());

        $this->assertIsArray($composer->getRequiresNames());
    }

    /**
     * Test the dependencies' names are returned.
     */
    public function testGetRequiresNames()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $requiresNames = $composer->getRequiresNames();

        $this->assertContains('tecnickcom/tcpdf', $requiresNames);
        $this->assertContains('setasign/fpdi', $requiresNames);
    }

    /**
     * Test PHP and ext- are not returned, since we won't be dealing with them.
     */
    public function testGetRequiresNamesDoesNotContain()
    {

        $testFile = __DIR__ . '/composerpackage-test-easypost-php.json';

        $composer = ComposerPackage::fromFile($testFile);

        $requiresNames = $composer->getRequiresNames();

        $this->assertNotContains('ext-curl', $requiresNames);
        $this->assertNotContains('php', $requiresNames);
    }


    /**
     *
     */
    public function testAutoloadPsr0()
    {

        $testFile = __DIR__ . '/composerpackage-test-easypost-php.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('psr-0', $autoload);

        $this->assertIsArray($autoload['psr-0']);
    }

    /**
     *
     */
    public function testAutoloadPsr4()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('psr-4', $autoload);

        $this->assertIsArray($autoload['psr-4']);
    }

    /**
     *
     */
    public function testAutoloadClassmap()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('classmap', $autoload);

        $this->assertIsArray($autoload['classmap']);
    }

    /**
     *
     */
    public function testAutoloadFiles()
    {

        $testFile = __DIR__ . '/composerpackage-test-php-di.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('files', $autoload);

        $this->assertIsArray($autoload['files']);
    }

    public function testPsr4Array()
    {

        $composerJson = <<<'EOD'
{
    "autoload": {
        "psr-4": { "Monolog\\": ["src/", "lib/"] }
    }
}

EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new ComposerPackage($composer);

        $autoload = $sut->getAutoload();

        $this->assertArrayHasKey('psr-4', $autoload);

        $psr4Autoload = $autoload['psr-4'];

        $this->assertArrayHasKey('Monolog\\', $psr4Autoload);

        $monologAutoload = $psr4Autoload['Monolog\\'];

        $this->assertContains('src/', $monologAutoload);
        $this->assertContains('lib/', $monologAutoload);
    }

    public function testOverrideAutoload()
    {
        $this->markTestIncomplete();
    }

    /**
     * When composer.json is not where it was specified, what error message (via Exception) should be returned?
     */
    public function testMissingComposer()
    {
        $this->markTestIncomplete();
    }
}
