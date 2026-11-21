<?php

namespace Drupal\dkan_catalog_ui\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Status report entries for the catalog UI.
 */
class RequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   Requirement entries keyed by id.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    if (!$this->moduleHandler->moduleExists('dkan_js_frontend')) {
      return [];
    }
    return [
      'dkan_catalog_ui_js_frontend' => [
        'title' => $this->t('DKAN Catalog UI'),
        'value' => $this->t('The dkan_js_frontend module is also enabled.'),
        'description' => $this->t('Both modules claim /dataset/{id} and /search. Aliased dataset pages resolve to the catalog UI; other paths go to the JavaScript frontend. Uninstall one of them.'),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

}
