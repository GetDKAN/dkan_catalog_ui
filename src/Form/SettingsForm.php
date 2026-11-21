<?php

namespace Drupal\dkan_catalog_ui\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dkan_catalog_ui\DatasetPage\DatasetMetadataInterface;
use Drupal\dkan_catalog_ui\DatasetPage\OverviewBuilder;

/**
 * Catalog UI settings: the dataset Overview metadata table.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    // Not readonly: forms are serialized (DependencySerializationTrait).
    protected DatasetMetadataInterface $datasetMetadata,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dkan_catalog_ui_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return string[]
   */
  protected function getEditableConfigNames(): array {
    return ['dkan_catalog_ui.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $schema = array_diff(array_keys($this->datasetMetadata->propertyLabels()), OverviewBuilder::SHOWN_ELSEWHERE);
    $form['overview'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataset Overview: Additional Information'),
      '#open' => TRUE,
    ];
    $form['overview']['overview_properties'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Metadata properties'),
      '#description' => $this->t('One property name per line, in display order. Labels come from the dataset schema titles. Names outside the schema show only on datasets whose metadata has them. Schema properties: %properties.', [
        '%properties' => implode(', ', $schema),
      ]),
      '#rows' => 16,
      '#config_target' => new ConfigTarget(
        'dkan_catalog_ui.settings',
        'overview_properties',
        fromConfig: fn (?array $properties): string => implode("\n", $properties ?? OverviewBuilder::DEFAULT_PROPERTIES),
        toConfig: fn (string $value): array => array_values(array_unique(array_filter(
          array_map('trim', preg_split('/\R/', $value) ?: []),
          fn (string $line): bool => $line !== '',
        ))),
      ),
    ];
    $form['overview']['overview_other_properties'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also show other schema properties'),
      '#description' => $this->t('Appends the remaining dataset schema properties that have a value, in schema order, so new schema fields show without editing the list.'),
      '#config_target' => 'dkan_catalog_ui.settings:overview_other_properties',
    ];
    return parent::buildForm($form, $form_state);
  }

}
