<?php
namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Console\Commands\Compose;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CleanupIntegrationTest
 * @package BrianHenryIE\Strauss\Tests\Integration
 * @coversNothing
 */
class CleanupIntegrationTest extends IntegrationTestCase
{

    public function testFilesAutoloader()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "symfony/polyfill-php80": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": true
    }
  }
}
EOD;
        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        assert(file_exists($this->testsWorkingDir . '/vendor/symfony/polyfill-php80/bootstrap.php'));

        $inputInterfaceMock = $this->createMock(InputInterface::class);
        $outputInterfaceMock = $this->createMock(OutputInterface::class);

        $strauss = new Compose();

        $result = $strauss->run($inputInterfaceMock, $outputInterfaceMock);

        $autoload_static_php = file_get_contents($this->testsWorkingDir .'vendor/composer/autoload_static.php');
        $this->assertStringNotContainsString("__DIR__ . '/..' . '/symfony/polyfill-php80/bootstrap.php'", $autoload_static_php);

        $autoload_files_php = file_get_contents($this->testsWorkingDir .'vendor/composer/autoload_files.php');
        $this->assertStringNotContainsString("\$vendorDir . '/symfony/polyfill-php80/bootstrap.php'", $autoload_files_php);

        $this->assertStringContainsString("\$baseDir . '/vendor-prefixed/symfony/polyfill-php80/bootstrap.php'", $autoload_files_php);
    }
}
