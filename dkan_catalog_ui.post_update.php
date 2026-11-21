<?php

/**
 * @file
 * Post update functions for the DKAN Catalog UI module.
 */

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Language\LanguageInterface;
use Drupal\dkan_catalog_ui\Alias\DatasetAliasManagerInterface;
use Drupal\dkan_catalog_ui\Hook\EntityHooks;
use Drupal\facets\Entity\Facet;
use Drupal\search_api\Entity\Index;

/**
 * Remove the legacy "Datasets" content link now that the module ships one.
 *
 * Only an exact match is removed: main menu, title "Datasets", link
 * internal:/search, no parent. Anything else is reported and kept.
 */
function dkan_catalog_ui_post_update_remove_search_menu_link(): string {
  return dkan_catalog_ui_remove_search_menu_link();
}

/**
 * Record ownership of the format index field on sites installed before it.
 *
 * The field this module adds carries the label it writes; a field a site
 * added itself would normally have a human label.
 */
function dkan_catalog_ui_post_update_flag_format_field(): string {
  $field = Index::load('dkan')?->getField('distribution__item__format');
  if ($field && $field->getLabel() === 'distribution__item__format') {
    \Drupal::state()->set(EntityHooks::FORMAT_FIELD_STATE_KEY, TRUE);
    return 'The format index field is now recorded as added by dkan_catalog_ui.';
  }
  return 'No module-added format index field found; nothing recorded.';
}

/**
 * Show ten values per shipped facet, with "Show more", where still unlimited.
 *
 * Facets a site has given its own soft limit are left alone.
 */
function dkan_catalog_ui_post_update_facet_soft_limit(): string {
  $updated = [];
  foreach (Facet::loadMultiple(['dcu_theme', 'dcu_keyword', 'dcu_publisher', 'dcu_format']) as $facet) {
    $widget = $facet->getWidget();
    if (($widget['config']['soft_limit'] ?? NULL) !== 0) {
      continue;
    }
    $widget['config']['soft_limit'] = 10;
    $facet->setWidget($widget['type'], $widget['config']);
    $facet->save();
    $updated[] = $facet->id();
  }
  return $updated ? 'Soft limit 10 set on: ' . implode(', ', $updated) . '.' : 'No unlimited shipped facets; nothing changed.';
}

/**
 * Make managed /dataset/{uuid} aliases language-neutral.
 *
 * Re-saves each candidate path_alias; the presave hook gives a managed alias
 * of a non-translatable dataset the "not specified" language. Nodes are not
 * re-saved (that would run DKAN's metastore lifecycle).
 *
 * @param array<string, mixed> $sandbox
 *   Batch progress.
 */
function dkan_catalog_ui_post_update_language_neutral_aliases(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $query = fn () => $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('alias', DatasetAliasManagerInterface::ALIAS_PREFIX . '%', 'LIKE')
    ->condition('path', '/node/%', 'LIKE')
    ->condition('langcode', LanguageInterface::LANGCODE_NOT_SPECIFIED, '<>');
  if (!isset($sandbox['last'])) {
    $sandbox['last'] = 0;
    $sandbox['changed'] = 0;
    $sandbox['total'] = (int) $query()->count()->execute();
    $sandbox['done'] = 0;
  }
  $ids = $query()->condition('id', $sandbox['last'], '>')->sort('id')->range(0, 50)->execute();
  foreach ($storage->loadMultiple($ids) as $alias) {
    // The field value, not language(): that object is cached on the entity.
    $before = $alias->get('langcode')->value;
    $alias->save();
    if ($alias->get('langcode')->value !== $before) {
      $sandbox['changed']++;
    }
    $sandbox['last'] = $alias->id();
    $sandbox['done']++;
  }
  $sandbox['#finished'] = ($ids && $sandbox['total']) ? min(1, $sandbox['done'] / $sandbox['total']) : 1;
  return "Dataset aliases made language-neutral: {$sandbox['changed']}.";
}

/**
 * Create dkan_catalog_ui.settings with the shipped Overview property list.
 */
function dkan_catalog_ui_post_update_overview_settings(): string {
  $config = \Drupal::configFactory()->getEditable('dkan_catalog_ui.settings');
  if (!$config->isNew()) {
    return 'dkan_catalog_ui.settings already exists; nothing changed.';
  }
  $path = \Drupal::service('extension.list.module')->getPath('dkan_catalog_ui') . '/config/install';
  $config->setData((new FileStorage($path))->read('dkan_catalog_ui.settings'))->save(TRUE);
  return 'Created dkan_catalog_ui.settings with the default Overview properties.';
}

/**
 * Delete the exact legacy Datasets link; return a summary.
 *
 * Shared with tests and the dev-site setup.
 */
function dkan_catalog_ui_remove_search_menu_link(): string {
  if (!\Drupal::moduleHandler()->moduleExists('menu_link_content')) {
    return 'menu_link_content is not installed; nothing to do.';
  }
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('menu_name', 'main')
    ->condition('link.uri', 'internal:/search')
    ->execute();
  if (!$ids) {
    return 'No content link to /search in the main menu.';
  }
  $removed = 0;
  $kept = [];
  /** @var \Drupal\menu_link_content\MenuLinkContentInterface $link */
  foreach ($storage->loadMultiple($ids) as $link) {
    if ($link->getTitle() === 'Datasets' && $link->getParentId() === '') {
      $link->delete();
      $removed++;
    }
    else {
      $kept[] = $link->getTitle();
    }
  }
  $summary = sprintf('Removed %d legacy Datasets link(s).', $removed);
  if ($kept) {
    $summary .= ' Kept: ' . implode(', ', $kept) . '.';
  }
  return $summary;
}
