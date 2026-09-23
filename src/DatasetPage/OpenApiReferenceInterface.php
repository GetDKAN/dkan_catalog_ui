<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

/**
 * Turns an OpenAPI 3 document into a flat, renderable endpoint list.
 */
interface OpenApiReferenceInterface {

  /**
   * Flatten the document's paths into endpoint entries.
   *
   * @param array $spec
   *   Decoded OpenAPI document.
   *
   * @return array[]
   *   One entry per path/method: method, path, summary, description, tag,
   *   parameters (name, in, required, description, type, example),
   *   request_example (pretty JSON or empty) and example_path (the path
   *   with example values substituted, empty when a value is unknown).
   */
  public function endpoints(array $spec): array;

  /**
   * Endpoints grouped by their first tag, in order of first appearance.
   *
   * @param array $spec
   *   Decoded OpenAPI document.
   *
   * @return array[]
   *   Entries of title and endpoints (as ::endpoints() returns them);
   *   untagged endpoints fall under "Other", last.
   */
  public function groupedEndpoints(array $spec): array;

}
