<?php

namespace Drupal\dkan_catalog_ui_preview;

/**
 * Column labels and descriptions from the resource's data dictionary.
 */
interface DictionaryHeadersInterface {

  /**
   * Dictionary titles and descriptions for a resource's columns.
   *
   * @param string $resource_id
   *   Resource id ("identifier__version").
   * @param array $fields
   *   Datastore schema fields keyed by machine name.
   *
   * @return array[]
   *   Keyed by column machine name: title, description and type (the
   *   Frictionless type); title or type may be empty, never both. Empty
   *   when the dictionary mode is "none" or no dictionary applies.
   *   Dictionary fields match a column by machine name, else by the
   *   column's original header.
   */
  public function labels(string $resource_id, array $fields): array;

  /**
   * Cache tags of the dictionary that applies to a resource.
   *
   * @return string[]
   *   Tags, empty when none applies.
   */
  public function cacheTags(string $resource_id): array;

}
