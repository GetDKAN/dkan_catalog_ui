<?php

namespace Drupal\dkan_catalog_ui\Search;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dkan_metastore\DataDictionary\DataDictionaryDiscoveryInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the dataset card shown in search results.
 */
class SearchResultBuilder implements SearchResultBuilderInterface {

  use StringTranslationTrait;

  /**
   * Maximum description length in characters.
   */
  const SUMMARY_LENGTH = 300;

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly DataDictionaryDiscoveryInterface $dictionaryDiscovery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, object $metadata): array {
    $url = $node->toUrl()->toString();
    $modified = (string) ($metadata->modified ?? '');
    $timestamp = $modified !== '' ? strtotime($modified) : FALSE;

    $formats = [];
    $hasTabular = FALSE;
    foreach ($metadata->distribution ?? [] as $dist) {
      if (!is_object($dist)) {
        continue;
      }
      $format = strtoupper((string) ($dist->format ?? ''));
      if ($format === '' && !empty($dist->mediaType)) {
        $format = strtoupper((string) (explode('/', (string) $dist->mediaType)[1] ?? ''));
      }
      if ($format !== '') {
        $formats[$format] = $format;
      }
      if (in_array(strtolower((string) ($dist->mediaType ?? '')), ['text/csv', 'text/tab-separated-values'], TRUE)) {
        $hasTabular = TRUE;
      }
    }

    $links = [];
    if ($hasTabular) {
      $links[] = ['label' => (string) $this->t('Data Table'), 'url' => $url . '#data-table', 'icon' => 'table'];
    }
    $links[] = ['label' => (string) $this->t('Overview'), 'url' => $url . '#overview', 'icon' => 'book'];
    if ($this->dictionaryDiscovery->getDataDictionaryMode() !== DataDictionaryDiscoveryInterface::MODE_NONE) {
      $links[] = ['label' => (string) $this->t('Data Dictionary'), 'url' => $url . '#data-dictionary', 'icon' => 'file'];
    }
    $links[] = ['label' => (string) $this->t('API'), 'url' => $url . '#api', 'icon' => 'code'];

    return [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui:dataset-card',
      '#props' => [
        'title' => (string) ($metadata->title ?? $node->label()),
        'url' => $url,
        'updated' => $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'F j, Y') : $modified,
        'description' => $this->summary((string) ($metadata->description ?? '')),
        'formats' => array_values($formats),
        'links' => $links,
      ],
      '#cache' => ['tags' => $node->getCacheTags()],
    ];
  }

  /**
   * Plain-text, truncated description.
   */
  protected function summary(string $description): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($description)) ?? '');
    return Unicode::truncate($text, self::SUMMARY_LENGTH, TRUE, TRUE);
  }

}
