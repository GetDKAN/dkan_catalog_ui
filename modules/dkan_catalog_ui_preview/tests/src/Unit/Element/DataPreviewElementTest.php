<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Unit\Element;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dkan_catalog_ui_preview\DataPreviewBuilderInterface;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface;
use Drupal\dkan_catalog_ui_preview\Element\DataPreview;
use Drupal\dkan_catalog_ui_preview\ImportStatusMessageInterface;
use Drupal\dkan_catalog_ui_preview\TableState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the dkan_catalog_ui_preview render element.
 */
#[Group('dkan_catalog_ui_preview')]
class DataPreviewElementTest extends UnitTestCase {

  /**
   * The mocked builder.
   *
   * @var \Drupal\dkan_catalog_ui_preview\DataPreviewBuilderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $builder;

  /**
   * The mocked logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Arguments of the last builder call.
   */
  protected ?array $builderArgs = NULL;

  /**
   * Set up a container whose builder runs the given callback.
   */
  protected function setContainerWithBuilder(callable $buildCallback, array $query = []): void {
    $this->builderArgs = NULL;

    $this->builder = $this->createMock(DataPreviewBuilderInterface::class);
    $this->builder->method('build')
      ->willReturnCallback(function ($dataSource, $resourceId, $state, $options) use ($buildCallback) {
        $this->builderArgs = [$dataSource, $resourceId, $state, $options];
        return $buildCallback($dataSource, $resourceId, $state, $options);
      });

    $dataSource = $this->createMock(DataSourceInterface::class);
    $dataSource->method('getSchema')->willReturn(['fields' => ['name' => ['type' => 'text']]]);

    $statusMessage = $this->createMock(ImportStatusMessageInterface::class);
    $statusMessage->method('build')->willReturn([
      '#tag' => 'p',
      '#value' => 'Data preview is not yet available.',
    ]);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);

    $requestStack = new RequestStack();
    $requestStack->push(Request::create('/node/1', 'GET', $query));

    $container = new ContainerBuilder();
    $container->set('dkan_catalog_ui.preview.builder', $this->builder);
    $container->set('dkan_catalog_ui.preview.data_source.database', $dataSource);
    $container->set('dkan_catalog_ui.preview.import_status_message', $statusMessage);
    $container->set('logger.factory', $loggerFactory);
    $container->set('request_stack', $requestStack);
    $container->set('config.factory', $this->getConfigFactoryStub(['dkan_datastore.settings' => ['rows_limit' => 20]]));
    \Drupal::setContainer($container);
  }

  /**
   * Element defaults.
   */
  public function testGetInfo(): void {
    $element = new DataPreview([], 'dkan_catalog_ui_preview', []);
    $info = $element->getInfo();

    $this->assertSame('', $info['#resource_id']);
    $this->assertNull($info['#caption']);
    $this->assertSame([[DataPreview::class, 'preRender']], $info['#pre_render']);
  }

  /**
   * No resource id: nothing is built.
   */
  public function testEmptyResourceId(): void {
    $this->setContainerWithBuilder(fn () => ['#theme' => 'dkan_catalog_ui_preview']);
    $element = ['#resource_id' => ''];
    $this->assertSame($element, DataPreview::preRender($element));
    $this->assertNull($this->builderArgs);
  }

  /**
   * The state is parsed from the request against the schema; caption passes.
   */
  public function testStateFromRequest(): void {
    $this->setContainerWithBuilder(fn () => ['#theme' => 'dkan_catalog_ui_preview'], [
      'sort' => 'name',
      'page_size' => '50',
      'columns' => 'nope',
    ]);

    $element = DataPreview::preRender([
      '#resource_id' => 'abc__1',
      '#caption' => 'a.csv',
      '#data_source_instance' => NULL,
    ]);

    [, $resourceId, $state, $options] = $this->builderArgs;
    $this->assertSame('abc__1', $resourceId);
    $this->assertInstanceOf(TableState::class, $state);
    $this->assertSame('name', $state->sort);
    // 50 exceeds the 20-row limit; the largest allowed size is used.
    $this->assertSame(10, $state->pageSize);
    $this->assertSame([], $state->columns);
    $this->assertSame('a.csv', $options['caption']);
    $this->assertSame(20, $options['max_page_size']);
    // No apply route: the toolbar panels are off.
    $this->assertNull($options['apply_url']);
    $this->assertFalse($options['panels']);
    $this->assertSame('dkan_catalog_ui_preview', $element['table']['#theme']);
  }

  /**
   * A data source instance overrides the service.
   */
  public function testDataSourceInstance(): void {
    $instance = $this->createMock(DataSourceInterface::class);
    $instance->method('getSchema')->willReturn([]);
    $this->setContainerWithBuilder(fn () => ['#theme' => 'dkan_catalog_ui_preview']);

    DataPreview::preRender([
      '#resource_id' => 'abc__1',
      '#caption' => NULL,
      '#data_source_instance' => $instance,
    ]);

    $this->assertSame($instance, $this->builderArgs[0]);
  }

  /**
   * An unavailable table renders the import status message.
   */
  public function testUnavailableRendersMessage(): void {
    $this->setContainerWithBuilder(fn () => NULL);

    $element = DataPreview::preRender([
      '#resource_id' => 'abc__1',
      '#caption' => NULL,
      '#data_source_instance' => NULL,
    ]);

    $this->assertSame('Data preview is not yet available.', $element['message']['#value']);
    $this->assertArrayNotHasKey('table', $element);
  }

  /**
   * A builder exception is logged and renders the status message.
   */
  public function testExceptionRendersMessage(): void {
    $this->setContainerWithBuilder(function () {
      throw new \RuntimeException('boom');
    });
    $this->logger->expects($this->once())->method('warning');

    $element = DataPreview::preRender([
      '#resource_id' => 'abc__1',
      '#caption' => NULL,
      '#data_source_instance' => NULL,
    ]);

    $this->assertSame('Data preview is not yet available.', $element['message']['#value']);
  }

}
