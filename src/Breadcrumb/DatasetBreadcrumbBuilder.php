<?php

namespace Drupal\dkan_catalog_ui\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Dataset pages: Home > Datasets (the current page is not a crumb).
 *
 * Dataset nodes live at /dataset/{uuid} with no path parent, so the default
 * path-based builder only yields "Home".
 */
class DatasetBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * The catalog search page route (module-owned view).
   */
  const SEARCH_ROUTE = 'view.dkan_catalog_ui_search.page_1';

  /**
   * Constructor.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL) {
    $cacheable_metadata?->addCacheContexts(['route']);
    if ($route_match->getRouteName() !== 'entity.node.canonical') {
      return FALSE;
    }
    // The decision reads a node field, so either outcome depends on the node.
    $node = $route_match->getParameter('node');
    if ($node instanceof NodeInterface) {
      $cacheable_metadata?->addCacheableDependency($node);
    }
    return self::isDataset($node);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $route_match->getParameter('node');
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $breadcrumb->addCacheableDependency($node);
    $breadcrumb->setLinks([
      Link::createFromRoute($this->t('Home'), '<front>'),
      Link::createFromRoute($this->t('Datasets'), self::SEARCH_ROUTE),
    ]);
    return $breadcrumb;
  }

  /**
   * Whether the route's node is a DKAN dataset.
   */
  protected static function isDataset(mixed $node): bool {
    return $node instanceof NodeInterface
      && $node->bundle() === 'data'
      && $node->hasField('field_data_type')
      && ($node->get('field_data_type')->value ?? '') === 'dataset';
  }

}
