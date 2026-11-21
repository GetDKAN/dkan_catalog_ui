<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Metadata dates (modified, issued) for display.
 *
 * DCAT dates are ISO 8601 strings, usually date-only. Formatting them in the
 * viewer's timezone shifts the day for some viewers, and the first render is
 * cached for everyone, so display uses the calendar date as written (its own
 * offset), held as midnight UTC.
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
   * The calendar date as written, at midnight UTC; NULL when unparseable.
   *
   * "2020-01-15T23:30:00-08:00" is January 15, not the UTC instant's 16th.
   */
  public static function calendarDate(string $value): ?\DateTimeImmutable {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    $utc = new \DateTimeZone(self::TIMEZONE);
    try {
      // A value with an offset keeps it; one without is read as UTC.
      $written = new \DateTimeImmutable($value, $utc);
      return new \DateTimeImmutable($written->format('Y-m-d'), $utc);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * The display date ("January 15, 2020"), or the raw value if unparseable.
   */
  public static function display(DateFormatterInterface $formatter, string $value): string {
    $date = self::calendarDate($value);
    if (!$date) {
      return $value;
    }
    return (string) $formatter->format($date->getTimestamp(), 'custom', self::DISPLAY_FORMAT, self::TIMEZONE);
  }

  /**
   * The ISO calendar date ("2020-01-15"), or '' if unparseable.
   */
  public static function iso(string $value): string {
    return self::calendarDate($value)?->format('Y-m-d') ?? '';
  }

}
