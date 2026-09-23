<?php

/**
 * @file
 * Post update functions for the DKAN Catalog UI module.
 */

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
