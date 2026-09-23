<?php

namespace Drupal\dkan_catalog_ui\Plugin\views\area;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders facet blocks inside a view so no theme block placement is needed.
 *
 * Facets are attached to this view's page display as their facet source; the
 * facets module adds them to the search query when the view executes, and
 * the blocks read the counts back here during rendering.
 */
#[ViewsArea('dkan_catalog_ui_facets')]
class FacetBlocks extends AreaPluginBase {

  /**
   * Item count above which a facet gets the "--long" class.
   *
   * Themes cap the height of long lists and scroll them.
   */
  const LONG_LIST = 8;

  /**
   * The block plugin manager.
   */
  protected BlockManagerInterface $blockManager;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->blockManager = $container->get('plugin.manager.block');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['facets'] = ['default' => ''];
    $options['heading'] = ['default' => 'Filter'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Heading'),
      '#default_value' => $this->options['heading'],
    ];
    $form['facets'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Facets'),
      '#description' => $this->t('Facet machine names, one per line, in display order.'),
      '#default_value' => $this->options['facets'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    $ids = array_filter(array_map('trim', preg_split('/\R/', (string) $this->options['facets'])));
    if (!$ids) {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dcu-facets'], 'id' => 'dcu-facets'],
    ];
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.query_args']);

    if ($this->options['heading'] !== '') {
      $build['heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->options['heading'],
        '#attributes' => ['class' => ['dcu-facets__heading']],
      ];
    }
    // Facets use the "f" query key (facet source filter_key); the link is
    // only useful with an active facet. url.query_args above covers it.
    $active = (array) ($this->view->getRequest()->query->all()['f'] ?? []);
    if (array_filter($active, fn($value) => is_string($value) && $value !== '')) {
      $build['clear'] = [
        '#type' => 'link',
        '#title' => $this->t('Clear all filters'),
        '#url' => Url::fromRoute('<current>'),
        '#attributes' => ['class' => ['dcu-facets__clear']],
      ];
    }

    foreach ($ids as $id) {
      try {
        $block = $this->blockManager->createInstance('facet_block:' . $id);
      }
      catch (PluginException) {
        continue;
      }
      $access = $block->access($this->currentUser, TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $content = $block->build();
      $cacheability->addCacheableDependency($block);
      if (!$content || (isset($content['#type']) && $content['#type'] === 'container' && empty(array_filter(array_keys($content), fn($k) => $k[0] !== '#')))) {
        continue;
      }
      $classes = ['dcu-facets__facet', 'dcu-facets__facet--' . $id];
      if (count($content[0]['#items'] ?? []) > self::LONG_LIST) {
        $classes[] = 'dcu-facets__facet--long';
      }
      $build[$id] = [
        '#type' => 'container',
        '#attributes' => ['class' => $classes],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $block->label(),
          '#attributes' => ['class' => ['dcu-facets__facet-label']],
        ],
        'content' => $content,
      ];
    }

    $cacheability->applyTo($build);
    return $build;
  }

}
