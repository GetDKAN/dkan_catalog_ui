<?php

namespace Drupal\dkan_catalog_ui_preview\DataSource;

/**
 * Value object holding one page of data source results.
 */
final class DataSourceResult {

  /**
   * Constructor.
   *
   * @param array $rows
   *   Row objects (\stdClass) keyed by column machine name.
   * @param int $totalCount
   *   Total number of rows matching the conditions, ignoring limit/offset.
   *   Drives paging.
   * @param int|null $unfilteredTotalCount
   *   Total rows in the resource ignoring the conditions, or NULL when the
   *   source does not report it.
   */
  public function __construct(
    public readonly array $rows,
    public readonly int $totalCount,
    public readonly ?int $unfilteredTotalCount = NULL,
  ) {}

}
