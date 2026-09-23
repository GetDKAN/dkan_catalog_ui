<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dkan_common\DataResource;
use Drupal\dkan_datastore\DatastoreService;
use Drupal\node\NodeInterface;

/**
 * Builds the Overview tab: resources and the metadata table.
 */
class OverviewBuilder implements OverviewBuilderInterface {

  use StringTranslationTrait;

  /**
   * Metadata properties shown in the table, in order.
   */
  const DEFAULT_PROPERTIES = [
    'modified',
    'issued',
    'publisher',
    'identifier',
    'contactPoint',
    'bureauCode',
    'programCode',
    'keyword',
    'theme',
    'license',
    'accessLevel',
    'temporal',
    'spatial',
    'accrualPeriodicity',
    'describedBy',
  ];

  /**
   * Mime type to display format.
   */
  const MIME_FORMATS = [
    'text/csv' => 'CSV',
    'text/tab-separated-values' => 'TSV',
    'application/json' => 'JSON',
    'application/pdf' => 'PDF',
    'application/zip' => 'ZIP',
    'application/vnd.ms-excel' => 'XLS',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
    'application/xml' => 'XML',
    'text/xml' => 'XML',
  ];

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DatasetMetadataInterface $datasetMetadata,
    protected readonly DistributionInfoInterface $distributionInfo,
    protected readonly DatastoreService $datastore,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, object $metadata): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dcu-dataset-overview']],
      '#cache' => ['tags' => $node->getCacheTags()],
    ];

    $resources = $this->buildResources($node, $metadata);
    if ($resources) {
      $build['resources'] = [
        '#type' => 'component',
        '#component' => 'dkan_catalog_ui:resource-list',
        '#props' => [
          'heading' => (string) $this->t('Resources'),
          'resources' => $resources,
        ],
        '#cache' => ['tags' => $this->distributionInfo->cacheTags($node)],
      ];
    }

    $rows = $this->buildRows($metadata);
    if ($rows) {
      $build['metadata'] = [
        '#type' => 'component',
        '#component' => 'dkan_catalog_ui:metadata-table',
        '#props' => [
          'heading' => (string) $this->t('Additional Information'),
          'rows' => $rows,
        ],
      ];
    }

    return $build;
  }

  /**
   * Build resource rows from metadata, matched to import info by URL path.
   */
  protected function buildResources(NodeInterface $node, object $metadata): array {
    $distributions = $metadata->distribution ?? [];
    if (!is_array($distributions) || !$distributions) {
      return [];
    }
    $infoByPath = $this->distributionInfo->byPath($node);

    $rows = [];
    foreach ($distributions as $dist) {
      if (!is_object($dist)) {
        continue;
      }
      $url = DownloadUrl::toString((string) ($dist->downloadURL ?? $dist->accessURL ?? ''));
      $path = $this->distributionInfo->urlPath($url);
      $title = (string) ($dist->title ?? '');
      if ($title === '') {
        $title = $url ? basename($path) : (string) $this->t('Resource');
      }
      $row = [
        'title' => $title,
        'format' => $this->format($dist, $path),
        'url' => $url,
        'description' => (string) ($dist->description ?? ''),
        'rows' => '',
        'columns' => '',
      ];
      $summary = $this->summary($infoByPath[$path] ?? NULL);
      if ($summary) {
        $row['rows'] = number_format($summary->numOfRows);
        $row['columns'] = number_format($summary->numOfColumns);
      }
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Row and column counts for an imported distribution, or NULL.
   */
  protected function summary(?array $distInfo): ?object {
    if (!$distInfo || empty($distInfo['resource_id']) || empty($distInfo['resource_version'])) {
      return NULL;
    }
    if (!in_array($distInfo['mime_type'] ?? '', DataResource::IMPORTABLE_FILE_TYPES)) {
      return NULL;
    }
    try {
      $summary = $this->datastore->summary($distInfo['resource_id'] . '__' . $distInfo['resource_version']);
      return is_object($summary) && isset($summary->numOfRows) ? $summary : NULL;
    }
    catch (\Throwable) {
      // Not imported yet, or the table is gone; counts are simply omitted.
      return NULL;
    }
  }

  /**
   * Display format for a distribution.
   */
  protected function format(object $dist, string $path): string {
    if (!empty($dist->format) && is_string($dist->format)) {
      return strtoupper($dist->format);
    }
    $mime = strtolower((string) ($dist->mediaType ?? ''));
    if (isset(self::MIME_FORMATS[$mime])) {
      return self::MIME_FORMATS[$mime];
    }
    return strtoupper(pathinfo($path, PATHINFO_EXTENSION));
  }

  /**
   * Build metadata table rows: label, value and optional href.
   */
  protected function buildRows(object $metadata): array {
    $labels = $this->datasetMetadata->propertyLabels();
    $rows = [];
    foreach (self::DEFAULT_PROPERTIES as $property) {
      if (!isset($metadata->{$property})) {
        continue;
      }
      $formatted = $this->formatValue($property, $metadata->{$property});
      if ($formatted === NULL) {
        continue;
      }
      $rows[] = ['label' => $this->label($property, $labels)] + $formatted;
    }
    return $rows;
  }

  /**
   * Display label for a property.
   */
  protected function label(string $property, array $schemaLabels): string {
    // Schema titles that read badly in a public table are overridden here.
    return (string) match ($property) {
      'contactPoint' => $this->t('Contact'),
      'bureauCode' => $this->t('Bureau Code'),
      'programCode' => $this->t('Program Code'),
      default => $schemaLabels[$property] ?? $property,
    };
  }

  /**
   * Format one metadata value for display.
   *
   * @return array|null
   *   ['value' => string, 'href' => string], or NULL to skip the row.
   */
  protected function formatValue(string $property, mixed $value): ?array {
    if ($property === 'contactPoint' && is_object($value)) {
      $name = (string) ($value->fn ?? '');
      if ($name === '') {
        return NULL;
      }
      $email = (string) ($value->hasEmail ?? '');
      if ($email !== '' && !str_starts_with(strtolower($email), 'mailto:')) {
        $email = 'mailto:' . $email;
      }
      return ['value' => $name, 'href' => $email];
    }
    if ($property === 'publisher' && is_object($value)) {
      $name = (string) ($value->name ?? '');
      return $name === '' ? NULL : ['value' => $name, 'href' => ''];
    }
    if (in_array($property, ['modified', 'issued'], TRUE) && is_string($value)) {
      $timestamp = strtotime($value);
      return [
        'value' => $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'F j, Y') : $value,
        'href' => '',
      ];
    }
    if (is_array($value)) {
      $strings = array_values(array_filter($value, 'is_string'));
      return $strings ? ['value' => implode(', ', $strings), 'href' => ''] : NULL;
    }
    if (is_string($value) || is_numeric($value)) {
      $string = (string) $value;
      if ($string === '') {
        return NULL;
      }
      $href = preg_match('@^https?://@i', $string) ? $string : '';
      return ['value' => $string, 'href' => $href];
    }
    return NULL;
  }

}
