<?php

namespace Drupal\dkan_catalog_ui\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dkan_catalog_ui\DatasetPage\OpenApiReferenceInterface;
use Drupal\dkan_common\DkanApiDocsGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The site API reference at /api/docs.
 *
 * Renders the same document DKAN serves at /api/1 as a grouped endpoint
 * list. The generator exposes no cache metadata and reads live data, so the
 * page caches for an hour without tags.
 */
class ApiDocsController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Seconds the rendered reference stays cached.
   */
  const MAX_AGE = 3600;

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DkanApiDocsGenerator $generator,
    protected readonly OpenApiReferenceInterface $reference,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dkan.common.docs_generator'),
      $container->get('dkan_catalog_ui.openapi_reference'),
      $container->get('logger.channel.dkan_catalog_ui'),
    );
  }

  /**
   * The reference page.
   */
  public function page(): array {
    $groups = [];
    try {
      $spec = json_decode((string) $this->generator->buildSpec(), TRUE);
      $groups = $this->reference->groupedEndpoints(is_array($spec) ? $spec : []);
    }
    catch (\Throwable $e) {
      $this->logger->error('Site API docs unavailable: @message', ['@message' => $e->getMessage()]);
    }
    if (!$groups) {
      if (!isset($e)) {
        $this->logger->error('Site API docs unavailable: the OpenAPI document has no endpoints.');
      }
      return [
        '#type' => 'component',
        '#component' => 'dkan_catalog_ui:api-docs',
        '#props' => [
          'intro' => (string) $this->t('The DKAN API provides programmatic access to the catalog: dataset metadata, datastore queries and downloads.'),
          'docs_url' => Url::fromRoute('dkan.common.api.version')->toString(),
          'endpoints' => [],
          'empty' => (string) $this->t('API documentation is unavailable.'),
        ],
        '#cache' => ['max-age' => 0],
      ];
    }
    foreach ($groups as &$group) {
      foreach ($group['endpoints'] as &$endpoint) {
        $endpoint['example_url'] = $endpoint['example_path'] !== ''
          ? Url::fromUri('base:' . ltrim($endpoint['example_path'], '/'))->toString()
          : '';
      }
      unset($endpoint);
    }
    unset($group);

    return [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui:api-docs',
      '#props' => [
        'intro' => (string) $this->t('The DKAN API provides programmatic access to the catalog: dataset metadata, datastore queries and downloads.'),
        'docs_url' => Url::fromRoute('dkan.common.api.version')->toString(),
        'links' => [
          [
            'label' => (string) $this->t('OpenAPI document (JSON)'),
            'url' => Url::fromRoute('dkan.common.api.version')->toString(),
          ],
          [
            'label' => (string) $this->t('OpenAPI document (YAML)'),
            'url' => Url::fromRoute('dkan.common.api.version.yaml')->toString(),
          ],
        ],
        'endpoints' => [],
        'groups' => $groups,
      ],
      '#cache' => ['max-age' => self::MAX_AGE],
    ];
  }

}
