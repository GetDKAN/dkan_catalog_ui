<?php

namespace Drupal\dkan_catalog_ui;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Integers grouped for the interface language ("2,969", "2.969", "2 969").
 *
 * Uses intl's \NumberFormatter when loaded, else English grouping. Render
 * arrays already vary by interface language (a required cache context).
 */
class NumberFormat {

  /**
   * Formatters by langcode.
   *
   * @var array<string, \NumberFormatter|null>
   */
  protected array $formatters = [];

  public function __construct(
    protected readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Format an integer with the interface language's grouping.
   */
  public function format(int $number): string {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    if (!array_key_exists($langcode, $this->formatters)) {
      $this->formatters[$langcode] = class_exists(\NumberFormatter::class)
        ? new \NumberFormatter($langcode, \NumberFormatter::DECIMAL)
        : NULL;
    }
    $formatted = $this->formatters[$langcode]?->format($number);
    return is_string($formatted) ? $formatted : number_format($number);
  }

}
