<?php

/**
 * @file
 * Compares shipped config with a config export from a fresh install.
 *
 * Usage, from the project root (where vendor/ is):
 *   php <module>/scripts/config-drift.php <export-dir> <config-dir>...
 *
 * Ignores uuid, _core, key order and whitespace runs in strings. Prints the
 * differing keys as dotted paths and exits 1 on any drift or missing file.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require getcwd() . '/vendor/autoload.php';

if ($argc < 3) {
  fwrite(STDERR, "Usage: php config-drift.php <export-dir> <config-dir>...\n");
  exit(2);
}

/**
 * Lists dotted-path differences between two parsed configs.
 *
 * @return string[]
 *   One line per difference.
 */
function config_drift(mixed $shipped, mixed $active, string $path = ''): array {
  if (is_array($shipped) && is_array($active)) {
    $out = [];
    foreach (array_unique(array_merge(array_keys($shipped), array_keys($active))) as $key) {
      if ($path === '' && in_array($key, ['uuid', '_core'], TRUE)) {
        continue;
      }
      $child = $path === '' ? (string) $key : "$path.$key";
      if (!array_key_exists($key, $shipped)) {
        $out[] = "+ $child = " . json_encode($active[$key]);
      }
      elseif (!array_key_exists($key, $active)) {
        $out[] = "- $child = " . json_encode($shipped[$key]);
      }
      else {
        $out = array_merge($out, config_drift($shipped[$key], $active[$key], $child));
      }
    }
    return $out;
  }
  $normalize = fn ($value) => is_string($value) ? trim((string) preg_replace('/\s+/', ' ', $value)) : $value;
  return $normalize($shipped) === $normalize($active) ? [] : ["~ $path: " . json_encode($shipped) . ' -> ' . json_encode($active)];
}

$export = $argv[1];
$failed = FALSE;
foreach (array_slice($argv, 2) as $dir) {
  foreach (glob("$dir/*.yml") ?: [] as $file) {
    $name = basename($file);
    if (!file_exists("$export/$name")) {
      echo "### $name: not in the export\n";
      $failed = TRUE;
      continue;
    }
    $drift = config_drift(Yaml::parseFile($file), Yaml::parseFile("$export/$name"));
    if ($drift) {
      echo "### $name (- shipped only, + export only, ~ changed)\n  " . implode("\n  ", $drift) . "\n";
      $failed = TRUE;
    }
  }
}
echo $failed ? "Shipped config drifts from the install.\n" : "No drift.\n";
exit($failed ? 1 : 0);
