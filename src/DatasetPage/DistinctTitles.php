<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

/**
 * Makes repeated distribution titles distinguishable.
 */
final class DistinctTitles {

  /**
   * Suffix repeated titles with the file name, then a number if still equal.
   *
   * Comparison ignores case. Unique titles are returned unchanged.
   *
   * @param array<array-key, string> $titles
   *   Titles to display.
   * @param array<array-key, string> $files
   *   File names under the same keys; missing or empty ones are skipped.
   *
   * @return array<array-key, string>
   *   Titles under the same keys.
   */
  public static function apply(array $titles, array $files): array {
    $counts = self::counts($titles);
    foreach ($titles as $key => $title) {
      $file = $files[$key] ?? '';
      if ($counts[mb_strtolower($title)] > 1 && $file !== '') {
        $titles[$key] = $title . ' (' . $file . ')';
      }
    }
    $counts = self::counts($titles);
    $seen = [];
    foreach ($titles as $key => $title) {
      $folded = mb_strtolower($title);
      if ($counts[$folded] > 1) {
        $seen[$folded] = ($seen[$folded] ?? 0) + 1;
        $titles[$key] = $title . ' (' . $seen[$folded] . ')';
      }
    }
    return $titles;
  }

  /**
   * Occurrences per case-folded title.
   *
   * @param array<array-key, string> $titles
   *   Titles.
   *
   * @return array<string, int>
   *   Counts keyed by lowercased title.
   */
  private static function counts(array $titles): array {
    $counts = [];
    foreach ($titles as $title) {
      $folded = mb_strtolower($title);
      $counts[$folded] = ($counts[$folded] ?? 0) + 1;
    }
    return $counts;
  }

}
