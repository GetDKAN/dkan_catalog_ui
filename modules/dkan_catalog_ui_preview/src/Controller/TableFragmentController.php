<?php

namespace Drupal\dkan_catalog_ui_preview\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\dkan_catalog_ui_preview\DataPreviewBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the rendered data table for in-place updates.
 *
 * The response is the same markup the dataset page renders for the request's
 * query string, so the script can swap it into the page.
 */
class TableFragmentController implements ContainerInjectionInterface {

  use DatasetRouteTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly EntityRepositoryInterface $entityRepository,
    protected readonly DataPreviewBuilderInterface $builder,
    protected readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('dkan_catalog_ui.preview.builder'),
      $container->get('renderer'),
    );
  }

  /**
   * The table markup for a dataset and the current query.
   */
  public function fragment(string $dataset): CacheableResponse {
    $node = $this->loadDataset($this->entityRepository, $dataset);
    $build = $this->builder->lazyBuild((int) $node->id());
    if (!$build) {
      throw new NotFoundHttpException();
    }
    $html = (string) $this->renderer->renderInIsolation($build);

    $response = new CacheableResponse($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    $response->addCacheableDependency(CacheableMetadata::createFromRenderArray($build)->setCacheMaxAge(0));
    $response->headers->set('X-Robots-Tag', 'noindex');
    return $response;
  }

}
