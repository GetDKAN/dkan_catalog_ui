<?php

namespace Drupal\dkan_catalog_ui\Drush\Commands;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\dkan_catalog_ui\Alias\DatasetAliasManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the DKAN Catalog UI module.
 */
class CatalogUiCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DatasetAliasManagerInterface $aliasManager,
  ) {
    parent::__construct();
  }

  /**
   * Create missing /dataset/{uuid} aliases for existing dataset nodes.
   */
  #[CLI\Command(name: 'dkan-catalog-ui:aliases', description: 'Create missing /dataset/{uuid} aliases for existing dataset nodes.')]
  public function aliases(): void {
    $created = $this->aliasManager->backfill();
    $this->logger()->success(dt('Created @count dataset aliases.', ['@count' => $created]));
  }

}
