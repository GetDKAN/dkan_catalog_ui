<?php

namespace Drupal\dkan_catalog_ui\Plugin\facets\processor;

use Drupal\Core\Cache\UnchangingCacheableDependencyTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\BuildProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;

/**
 * Re-cases facet display values.
 *
 * DKAN's search index lowercases theme, keyword and publisher values (the
 * ignorecase processor), so facet labels arrive as "city planning". This
 * restores presentable casing for display only; raw values are untouched.
 *
 * @FacetsProcessor(
 *   id = "dcu_case",
 *   label = @Translation("Re-case display values"),
 *   description = @Translation("Capitalize words or upper-case the display value of each result."),
 *   stages = {
 *     "build" = 40
 *   }
 * )
 */
class CaseProcessor extends ProcessorPluginBase implements BuildProcessorInterface {

  // A pure string transform: without this, ProcessorPluginBase's max-age 0
  // makes every facet, the search query and the search page uncacheable.
  use UnchangingCacheableDependencyTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['mode' => 'ucwords'] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet) {
    $form['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Mode'),
      '#options' => [
        'ucwords' => $this->t('Capitalize each word'),
        'upper' => $this->t('Upper case'),
      ],
      '#default_value' => $this->getConfiguration()['mode'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet, array $results) {
    $mode = $this->getConfiguration()['mode'] ?? 'ucwords';
    foreach ($results as $result) {
      $value = (string) $result->getDisplayValue();
      $result->setDisplayValue($mode === 'upper' ? mb_strtoupper($value) : self::capitalizeWords($value));
    }
    return $results;
  }

  /**
   * Upper-case the first letter of each word; other letters are untouched.
   *
   * Words start after whitespace, "-", "_" or "/", as with ucwords(), but
   * any Unicode lower-case letter qualifies.
   */
  public static function capitalizeWords(string $value): string {
    return preg_replace_callback('@(^|[\s\-_/])(\p{Ll})@u', fn(array $m) => $m[1] . mb_strtoupper($m[2]), $value) ?? $value;
  }

}
