<?php

namespace Drupal\dkan_catalog_ui_preview\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\dkan_catalog_ui_preview\Install\Requirements\DkanCatalogUiPreviewRequirements;

/**
 * Status report warning while DKAN core's preview is also enabled.
 *
 * Installing alongside dkan_datastore_preview is refused
 * (DkanCatalogUiPreviewRequirements); enabling it afterwards cannot be
 * prevented, so runtime warns.
 */
class RequirementsHooks {

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
    if (!$this->moduleHandler->moduleExists('dkan_datastore_preview')) {
      return [];
    }
    return DkanCatalogUiPreviewRequirements::conflict(RequirementSeverity::Warning);
  }

}
