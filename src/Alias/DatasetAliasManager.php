<?php

namespace Drupal\dkan_catalog_ui\Alias;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Maintains /dataset/{uuid} path aliases for dataset nodes.
 *
 * Aliases are written through the core path field so langcode, updates and
 * deletion follow the node. When pathauto is installed the item is flagged
 * PathautoState::SKIP so a site pattern never overwrites the alias.
 */
class DatasetAliasManager implements DatasetAliasManagerInterface {

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return $node->bundle() === 'data'
      && $node->hasField('field_data_type')
      && ($node->get('field_data_type')->value ?? '') === 'dataset'
      && $node->hasField('path')
      && !empty($node->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function aliasFor(NodeInterface $node): string {
    return self::ALIAS_PREFIX . $node->uuid();
  }

  /**
   * {@inheritdoc}
   */
  public function apply(NodeInterface $node): void {
    if (!$this->applies($node)) {
      return;
    }
    $alias = $this->aliasFor($node);
    $list = $node->get('path');
    $item = $list->first() ?? $list->appendItem();
    if ($item->alias !== $alias) {
      $item->alias = $alias;
    }
    // Value 0 is \Drupal\pathauto\PathautoState::SKIP; the property only
    // exists when pathauto is installed.
    if ($item->getDataDefinition()->getPropertyDefinition('pathauto')) {
      $item->pathauto = 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function backfill(): int {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $aliasStorage = $this->entityTypeManager->getStorage('path_alias');

    $nids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'data')
      ->condition('field_data_type', 'dataset')
      ->execute();

    $created = 0;
    foreach (array_chunk($nids, 50) as $chunk) {
      /** @var \Drupal\node\NodeInterface $node */
      foreach ($nodeStorage->loadMultiple($chunk) as $node) {
        if (!$this->applies($node)) {
          continue;
        }
        $path = '/node/' . $node->id();
        $alias = $this->aliasFor($node);
        $exists = FALSE;
        foreach ($aliasStorage->loadByProperties(['path' => $path]) as $existing) {
          if ($existing->getAlias() === $alias) {
            $exists = TRUE;
            break;
          }
        }
        if ($exists) {
          continue;
        }
        $aliasStorage->create([
          'path' => $path,
          'alias' => $alias,
          'langcode' => $node->language()->getId(),
        ])->save();
        $created++;
      }
      $nodeStorage->resetCache($chunk);
    }
    return $created;
  }

}
