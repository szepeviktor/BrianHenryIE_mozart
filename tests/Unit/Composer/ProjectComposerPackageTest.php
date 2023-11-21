<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Composer\ProjectComposerPackage
 */
class ProjectComposerPackageTest extends TestCase
{

    /**
     * A simple test to check the getters all work.
     */
    public function testParseJson()
    {

        $testFile = __DIR__ . '/projectcomposerpackage-test-1.json';

        $composer = new ProjectComposerPackage($testFile);

        $config = $composer->getStraussConfig();

        $this->assertInstanceOf(StraussConfig::class, $config);
    }

    /**
     * @covers ::getFlatAutoloadKey
     */
    public function testGetFlatAutoloadKey()
    {

        $testFile = __DIR__ . '/projectcomposerpackage-test-getProjectPhpFiles.json';

        $composer = new ProjectComposerPackage($testFile);

        $phpFiles = $composer->getFlatAutoloadKey();

        $expected = ["src","includes","classes","functions.php"];

        self::assertEquals($expected, $phpFiles);
    }
}
