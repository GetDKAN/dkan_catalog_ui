<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dkan_metastore\DatasetApiDocs;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the API tab: a server-rendered reference of the dataset's endpoints.
 *
 * The reference is derived from the same per-dataset OpenAPI document DKAN
 * serves at /api/1/metastore/schemas/dataset/items/{id}/docs, so it needs no
 * client-side API console. The raw document stays linked for tools.
 */
class ApiBuilder implements ApiBuilderInterface {

  use StringTranslationTrait;

  /**
   * Route of the per-dataset OpenAPI document.
   */
  const DOCS_ROUTE = 'dkan_metastore.1.metastore.schemas.dataset.items.id.docs';

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DatasetApiDocs $docs,
    protected readonly OpenApiReferenceInterface $reference,
    protected readonly DistributionInfoInterface $distributionInfo,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, object $metadata): array {
    $uuid = (string) $node->uuid();
    $docsUrl = Url::fromRoute(self::DOCS_ROUTE, ['identifier' => $uuid])->toString();

    $endpoints = [];
    try {
      $spec = $this->docs->getDatasetSpecific($uuid);
      $endpoints = $this->reference->endpoints(is_array($spec) ? $spec : []);
    }
    catch (\Throwable $e) {
      $this->logger->notice('API docs unavailable for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
    foreach ($endpoints as &$endpoint) {
      $endpoint['example_url'] = $endpoint['example_path'] !== ''
        ? Url::fromUri('base:' . ltrim($endpoint['example_path'], '/'))->toString()
        : '';
    }
    unset($endpoint);

    return [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui:api-docs',
      '#props' => [
        'docs_url' => $docsUrl,
        'endpoints' => $endpoints,
      ],
      '#cache' => [
        'tags' => Cache::mergeTags($node->getCacheTags(), $this->distributionInfo->cacheTags($node)),
      ],
    ];
  }

}
