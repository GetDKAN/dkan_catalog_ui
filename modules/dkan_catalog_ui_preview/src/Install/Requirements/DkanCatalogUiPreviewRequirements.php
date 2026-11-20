<?php

namespace Drupal\dkan_catalog_ui_preview\Install\Requirements;

use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

/**
 * Refuses install while DKAN core's dkan_datastore_preview is enabled.
 *
 * Both render a datastore preview for dataset nodes; on dataset pages this
 * module drops the core one (DataPreviewHooks::nodeViewAlter()).
 */
class DkanCatalogUiPreviewRequirements implements InstallRequirementsInterface {

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   Requirement entries keyed by id.
   */
  public static function getRequirements(): array {
    if (!\Drupal::moduleHandler()->moduleExists('dkan_datastore_preview')) {
      return [];
    }
    return self::conflict(RequirementSeverity::Error);
  }

  /**
   * The conflict entry at the given severity.
   *
   * @return array<string, array<string, mixed>>
   *   Requirement entries keyed by id.
   */
  public static function conflict(RequirementSeverity $severity): array {
    return [
      'dkan_catalog_ui_preview_conflict' => [
        'title' => t('DKAN Catalog UI Preview'),
        'value' => t('The dkan_datastore_preview module is also enabled.'),
        'description' => t('Both modules render a datastore preview for dataset nodes. The catalog UI table replaces the core preview on dataset pages; uninstall dkan_datastore_preview.'),
        'severity' => $severity,
      ],
    ];
  }

}
