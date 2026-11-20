<?php

namespace Drupal\Tests\dkan_catalog_ui\Unit;

use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\dkan_catalog_ui\NumberFormat;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Counts follow the interface language's grouping.
 */
#[Group('dkan_catalog_ui')]
class NumberFormatTest extends UnitTestCase {

  /**
   * English, German and French grouping.
   */
  #[RequiresPhpExtension('intl')]
  public function testFormat(): void {
    $this->assertSame('1,234,567', $this->formatter('en')->format(1234567));
    $this->assertSame('1.234.567', $this->formatter('de')->format(1234567));
    $this->assertSame("1\u{202F}234\u{202F}567", $this->formatter('fr')->format(1234567));
    $this->assertSame('0', $this->formatter('en')->format(0));
  }

  /**
   * A formatter whose interface language is the given langcode.
   */
  protected function formatter(string $langcode): NumberFormat {
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn(new Language(['id' => $langcode]));
    return new NumberFormat($languageManager);
  }

}
