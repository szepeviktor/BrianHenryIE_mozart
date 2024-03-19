<?php

namespace BrianHenryIE\Strauss\Tests\Unit;

use BrianHenryIE\Strauss\ChangeEnumerator;
use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Prefixer;
use Composer\Composer;
use BrianHenryIE\Strauss\TestCase;

class ChangeEnumeratorTest extends TestCase
{

    // PREG_BACKTRACK_LIMIT_ERROR

    // Single implied global namespace.
    // Single named namespace.
    // Single explicit global namespace.
    // Multiple namespaces.



    public function testSingleNamespace()
    {

        $validPhp = <<<'EOD'
<?php
namespace MyNamespace;

class MyClass {
}
EOD;

        $config = $this->createMock(StraussConfig::class);
        $config->method('getNamespacePrefix')->willReturn('Prefix');
        $sut = new ChangeEnumerator($config);

        $sut->find($validPhp);

        self::assertArrayHasKey('MyNamespace', $sut->getDiscoveredNamespaces(), 'Found: ' . implode(',', $sut->getDiscoveredNamespaces()));
        self::assertContains('Prefix\MyNamespace', $sut->getDiscoveredNamespaces());

        self::assertNotContains('MyClass', $sut->getDiscoveredClasses());
    }

    public function testGlobalNamespace()
    {

        $validPhp = <<<'EOD'
<?php
namespace {
    class MyClass {
    }
}
EOD;

        $config = $this->createMock(StraussConfig::class);
        $sut = new ChangeEnumerator($config);

        $sut->find($validPhp);

        self::assertContains('MyClass', $sut->getDiscoveredClasses());
    }

    /**
     *
     */
    public function testMultipleNamespace()
    {

        $validPhp = <<<'EOD'
<?php
namespace MyNamespace {
}
namespace {
    class MyClass {
    }
}
EOD;

        $config = $this->createMock(StraussConfig::class);
        $sut = new ChangeEnumerator($config);

        $sut->find($validPhp);

        self::assertContains('\MyNamespace', $sut->getDiscoveredNamespaces());

        self::assertContains('MyClass', $sut->getDiscoveredClasses());
    }


    /**
     *
     */
    public function testMultipleNamespaceGlobalFirst()
    {

        $validPhp = <<<'EOD'
<?php

namespace {
    class MyClass {
    }
}
namespace MyNamespace {
    class MyOtherClass {
    }
}
EOD;

        $config = $this->createMock(StraussConfig::class);
        $sut = new ChangeEnumerator($config);

        $sut->find($validPhp);

        self::assertContains('\MyNamespace', $sut->getDiscoveredNamespaces());

        self::assertContains('MyClass', $sut->getDiscoveredClasses());
        self::assertNotContains('MyOtherClass', $sut->getDiscoveredClasses());
    }

    public function testItDoesNotFindNamespaceInComment(): void
    {

        $validPhp = <<<'EOD'
<?php

/**
 * @todo Rewrite to use Interchange objects
 */
class HTMLPurifier_Printer_ConfigForm extends HTMLPurifier_Printer
{

    /**
     * Returns HTML output for a configuration form
     * @param HTMLPurifier_Config|array $config Configuration object of current form state, or an array
     *        where [0] has an HTML namespace and [1] is being rendered.
     * @param array|bool $allowed Optional namespace(s) and directives to restrict form to.
     * @param bool $render_controls
     * @return string
     */
    public function render($config, $allowed = true, $render_controls = true)
    {

        // blah

        return $ret;
    }

}

// vim: et sw=4 sts=4
EOD;

        $config = $this->createMock(StraussConfig::class);
        $sut = new ChangeEnumerator($config);

        try {
            $sut->find($validPhp);
        } catch (\PHPUnit\Framework\Error\Warning $e) {
            self::fail('Should not throw an exception');
        }

        self::assertEmpty($sut->getDiscoveredNamespaces());
    }

    /**
     *
     */
    public function testMultipleClasses()
    {

        $validPhp = <<<'EOD'
<?php
class MyClass {
}
class MyOtherClass {

}
EOD;

        $config = $this->createMock(StraussConfig::class);
        $sut = new ChangeEnumerator($config);

        $sut->find($validPhp);

        self::assertContains('MyClass', $sut->getDiscoveredClasses());
        self::assertContains('MyOtherClass', $sut->getDiscoveredClasses());
    }

    /**
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_comments_as_classes()
    {
        $contents = "
    	// A class as good as any.
    	class Whatever {
    	
    	}
    	";

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertNotContains('as', $changeEnumerator->getDiscoveredClasses());
        self::assertContains('Whatever', $changeEnumerator->getDiscoveredClasses());
    }

    /**
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_multiline_comments_as_classes()
    {
        $contents = "
    	 /**
    	  * A class as good as any; class as.
    	  */
    	class Whatever {
    	}
    	";

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertNotContains('as', $changeEnumerator->getDiscoveredClasses());
        self::assertContains('Whatever', $changeEnumerator->getDiscoveredClasses());
    }

    /**
     * This worked without adding the expected regex:
     *
     * // \s*\\/?\\*{2,}[^\n]* |                        # Skip multiline comment bodies
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_multiline_comments_opening_line_as_classes()
    {
        $contents = "
    	 /** A class as good as any; class as.
    	  *
    	  */
    	class Whatever {
    	}
    	";

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertNotContains('as', $changeEnumerator->getDiscoveredClasses());
        self::assertContains('Whatever', $changeEnumerator->getDiscoveredClasses());
    }


    /**
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_multiline_comments_on_one_line_as_classes()
    {
        $contents = "
    	 /** A class as good as any; class as. */ class Whatever_Trevor {
    	}
    	";

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertNotContains('as', $changeEnumerator->getDiscoveredClasses());
        self::assertContains('Whatever_Trevor', $changeEnumerator->getDiscoveredClasses());
    }

    /**
     * If someone were to put a semicolon in the comment it would mess with the previous fix.
     *
     * @author BrianHenryIE
     *
     * @test
     */
    public function test_it_does_not_treat_comments_with_semicolons_as_classes()
    {
        $contents = "
    	// A class as good as any; class as versatile as any.
    	class Whatever_Ever {
    	
    	}
    	";

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertNotContains('as', $changeEnumerator->getDiscoveredClasses());
        self::assertContains('Whatever_Ever', $changeEnumerator->getDiscoveredClasses());
    }

    /**
     * @author BrianHenryIE
     */
    public function test_it_parses_classes_after_semicolon()
    {

        $contents = "
	    myvar = 123; class Pear { };
	    ";

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertContains('Pear', $changeEnumerator->getDiscoveredClasses());
    }


    /**
     * @author BrianHenryIE
     */
    public function test_it_parses_classes_followed_by_comment()
    {

        $contents = <<<'EOD'
	class WP_Dependency_Installer {
		/**
		 *
		 */
EOD;

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertContains('WP_Dependency_Installer', $changeEnumerator->getDiscoveredClasses());
    }


    /**
     * It's possible to have multiple namespaces inside one file.
     *
     * To have two classes in one file, one in a namespace and the other not, the global namespace needs to be explicit.
     *
     * @author BrianHenryIE
     *
     * @test
     */
    public function it_does_not_replace_inside_named_namespace_but_does_inside_explicit_global_namespace_a(): void
    {

        $contents = "
		namespace My_Project {
			class A_Class { }
		}
		namespace {
			class B_Class { }
		}
		";

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertNotContains('A_Class', $changeEnumerator->getDiscoveredClasses());
        self::assertContains('B_Class', $changeEnumerator->getDiscoveredClasses());
    }

    public function testExcludePackagesFromPrefix()
    {

        $config = $this->createMock(StraussConfig::class);
        $config->method('getExcludePackagesFromPrefixing')->willReturn(
            array('brianhenryie/pdfhelpers')
        );

        $dir = '';
        $composerPackage = $this->createMock(ComposerPackage::class);
        $composerPackage->method('getPackageName')->willReturn('brianhenryie/pdfhelpers');
        $filesArray = array(
            'irrelevantPath' => array(
                'dependency' => $composerPackage
            ),
        );

        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->findInFiles($dir, $filesArray);

        self::assertEmpty($changeEnumerator->getDiscoveredNamespaces());
    }


    public function testExcludeFilePatternsFromPrefix()
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('getExcludeFilePatternsFromPrefixing')->willReturn(
            array('/to/')
        );

        $dir = '';
        $composerPackage = $this->createMock(ComposerPackage::class);
        $composerPackage->method('getPackageName')->willReturn('brianhenryie/pdfhelpers');
        $filesArray = array(
            'path/to/file' => array(
                'dependency' => $composerPackage
            ),
        );

        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->findInFiles($dir, $filesArray);

        self::assertEmpty($changeEnumerator->getDiscoveredNamespaces());
    }

    /**
     * Test custom replacements
     */
    public function testNamespaceReplacementPatterns()
    {

        $contents = "
		namespace BrianHenryIE\PdfHelpers {
			class A_Class { }
		}
		";

        $config = $this->createMock(StraussConfig::class);
        $config->method('getNamespacePrefix')->willReturn('BrianHenryIE\Prefix');
        $config->method('getNamespaceReplacementPatterns')->willReturn(
            array('/BrianHenryIE\\\\(PdfHelpers)/'=>'BrianHenryIE\\Prefix\\\\$1')
        );

        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertArrayHasKey('BrianHenryIE\PdfHelpers', $changeEnumerator->getDiscoveredNamespaces());
        self::assertContains('BrianHenryIE\Prefix\PdfHelpers', $changeEnumerator->getDiscoveredNamespaces());
        self::assertNotContains('BrianHenryIE\Prefix\BrianHenryIE\PdfHelpers', $changeEnumerator->getDiscoveredNamespaces());
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/19
     */
    public function testPhraseClassObjectIsNotMistaken()
    {

        $contents = <<<'EOD'
<?php

class TCPDF_STATIC
{

    /**
     * Creates a copy of a class object
     * @param $object (object) class object to be cloned
     * @return cloned object
     * @since 4.5.029 (2009-03-19)
     * @public static
     */
    public static function objclone($object)
    {
        if (($object instanceof Imagick) and (version_compare(phpversion('imagick'), '3.0.1') !== 1)) {
            // on the versions after 3.0.1 the clone() method was deprecated in favour of clone keyword
            return @$object->clone();
        }
        return @clone($object);
    }
}
EOD;

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertNotContains('object', $changeEnumerator->getDiscoveredClasses());
    }

    public function testDefineConstant()
    {

        $contents = <<<'EOD'
/*******************************************************************************
 * FPDF                                                                         *
 *                                                                              *
 * Version: 1.83                                                                *
 * Date:    2021-04-18                                                          *
 * Author:  Olivier PLATHEY                                                     *
 *******************************************************************************
 */

define('FPDF_VERSION', '1.83');

define('ANOTHER_CONSTANT', '1.83');

class FPDF
{
EOD;

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        $constants = $changeEnumerator->getDiscoveredConstants();

        self::assertContains('FPDF_VERSION', $constants);
        self::assertContains('ANOTHER_CONSTANT', $constants);
    }

    public function test_commented_namespace_is_invalid(): void
    {

        $contents = <<<'EOD'
<?php

// Global. - namespace WPGraphQL;

use WPGraphQL\Utils\Preview;

/**
 * Class WPGraphQL
 *
 * This is the one true WPGraphQL class
 *
 * @package WPGraphQL
 */
final class WPGraphQL {

}
EOD;

        $config = $this->createMock(StraussConfig::class);
        $changeEnumerator = new ChangeEnumerator($config);
        $changeEnumerator->find($contents);

        self::assertArrayNotHasKey('WPGraphQL', $changeEnumerator->getDiscoveredNamespaces());
        self::assertContains('WPGraphQL', $changeEnumerator->getDiscoveredClasses());
    }
}
