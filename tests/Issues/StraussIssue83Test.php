<?php
/**
 * instanceof not prefixed properly.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/83
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\Compose;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue83Test extends \BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase
{
    public function test_namespace_keyword_on_opening_line()
    {
        self::markTestSkipped('Slow test.');

        $composerJsonString = <<<'EOD'
{
  "name": "issue/83",
  "require": {
    "aws/aws-sdk-php": "3.293.8"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "exclude_from_copy": {
		  "file_patterns": [
		    "/^((?!aws\\/aws-sdk-php).)*$/"
		  ]
      }
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

        $php_string = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/aws/aws-sdk-php/src/ClientResolver.php');

        self::assertStringNotContainsString('$value instanceof \Aws\EndpointV2\EndpointProviderV2', $php_string);
        self::assertStringContainsString('$value instanceof \Company\Project\Aws\EndpointV2\EndpointProviderV2', $php_string);
    }
}
