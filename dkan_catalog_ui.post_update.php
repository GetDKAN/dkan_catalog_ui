<?php

/**
 * @file
 * Post update functions for the DKAN Catalog UI module.
 */

use Drupal\dkan_catalog_ui\Hook\EntityHooks;
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
