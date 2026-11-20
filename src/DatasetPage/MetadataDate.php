<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Metadata dates (modified, issued) as UTC instants.
 *
 * DCAT dates are ISO 8601 strings, usually date-only. Formatting them in the
 * viewer's timezone shifts the day for some viewers, and the first render is
 * cached for everyone, so every reader parses and formats them in UTC.
 */
final class MetadataDate {

  const TIMEZONE = 'UTC';

  const DISPLAY_FORMAT = 'F j, Y';

  /**
   * Parse a metadata date, converted to UTC; NULL when unparseable.
   */
  public static function parse(string $value): ?\DateTimeImmutable {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    $utc = new \DateTimeZone(self::TIMEZONE);
    try {
      return (new \DateTimeImmutable($value, $utc))->setTimezone($utc);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * The display date ("January 15, 2020"), or the raw value if unparseable.
   */
  public static function display(DateFormatterInterface $formatter, string $value): string {
    $date = self::parse($value);
    if (!$date) {
      return $value;
    }
    return (string) $formatter->format($date->getTimestamp(), 'custom', self::DISPLAY_FORMAT, self::TIMEZONE);
  }

  /**
   * The ISO calendar date ("2020-01-15"), or '' if unparseable.
   */
  public static function iso(string $value): string {
    return self::parse($value)?->format('Y-m-d') ?? '';
  }

}
