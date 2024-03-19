<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/65
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\Compose;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue65Test extends IntegrationTestCase
{

    /**
     */
    public function test_aws_prefixed_functions()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-65-aws-prefixed-functions",
  "require": {
    "aws/aws-sdk-php": "3.268.17"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Issue65\\",
      "classmap_prefix": "BH_Strauss_Issue65_"
    },
    "aws/aws-sdk-php": [
        "S3"
    ]
  },
  "scripts": {
    "pre-autoload-dump": "Aws\\Script\\Composer\\Composer::removeUnusedServices"
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

        // vendor/aws/aws-sdk-php/src/Endpoint/UseDualstackEndpoint/Configuration.php

        self::assertNotEquals(1, $result);

        $php_string = file_get_contents($this->testsWorkingDir .'vendor-prefixed/aws/aws-sdk-php/src/Endpoint/UseDualstackEndpoint/Configuration.php');

        self::assertStringNotContainsString('$this->useDualstackEndpoint = Aws\boolean_value($useDualstackEndpoint);', $php_string);
        self::assertStringNotContainsString('$this->useDualstackEndpoint = BrianHenryIE\Issue65\Aws\boolean_value($useDualstackEndpoint);', $php_string);
        self::assertStringContainsString('$this->useDualstackEndpoint = \BrianHenryIE\Issue65\Aws\boolean_value($useDualstackEndpoint);', $php_string);
    }
}
