<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;

/**
 * Validates metadata download URLs before they become links.
 */
final class DownloadUrl {

  /**
   * Build a Url for an absolute http(s) URL from metadata, or NULL.
   */
  public static function fromString(string $url): ?Url {
    $url = trim($url);
    if ($url === '' || !UrlHelper::isValid($url, TRUE) || UrlHelper::stripDangerousProtocols($url) !== $url) {
      return NULL;
    }
    if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], TRUE)) {
      return NULL;
    }
    try {
      return Url::fromUri($url);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * The validated URL as a string, or '' when invalid.
   */
  public static function toString(string $url): string {
    $valid = self::fromString($url);
    return $valid ? $valid->toString() : '';
  }

}
