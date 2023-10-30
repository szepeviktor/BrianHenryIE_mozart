<?php
/**
 * Test PSR-4 array of autoload values.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/76
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\Compose;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue76Test extends \BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase
{

    /**
     */
    public function test_psr4_array()
    {

        $composerJsonString = <<<'EOD'
{
  "autoload": {
    "psr-4": {
      "FakerPress\\": [
        "src/FakerPress/",
        "src/functions/"
      ],
      "FakerPress\\Dev\\": "dev/src/"
    }
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "FakerPress\\ThirdParty\\",
      "classmap_prefix": "FakerPress_ThirdParty_",
      "constant_prefix": "FAKERPRESS__"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $inputInterfaceMock = $this->createMock(InputInterface::class);
        $outputInterfaceMock = $this->createMock(OutputInterface::class);

        $strauss = new Compose();

        $result = $strauss->run($inputInterfaceMock, $outputInterfaceMock);

        self::assertEquals(0, $result);
    }
}
