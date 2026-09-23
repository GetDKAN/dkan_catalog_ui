<?php

namespace Drupal\dkan_catalog_ui_preview\Controller;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the {dataset} route parameter to an accessible dataset node.
 */
trait DatasetRouteTrait {

  /**
   * Load the dataset node by UUID, enforcing view access.
   *
   * The UUID is the metastore identifier and is not always UUID-shaped.
   */
  protected function loadDataset(EntityRepositoryInterface $entityRepository, string $uuid): NodeInterface {
    $node = $entityRepository->loadEntityByUuid('node', $uuid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'data' || ($node->get('field_data_type')->value ?? '') !== 'dataset') {
      throw new NotFoundHttpException();
    }
    if (!$node->access('view')) {
      throw new AccessDeniedHttpException();
    }
    return $node;
  }

}
