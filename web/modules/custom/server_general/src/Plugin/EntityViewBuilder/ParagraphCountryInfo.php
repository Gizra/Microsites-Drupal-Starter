<?php

declare(strict_types=1);

namespace Drupal\server_general\Plugin\EntityViewBuilder;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\pluggable_entity_view_builder\EntityViewBuilderPluginAbstract;
use Drupal\server_general\ProcessedTextBuilderTrait;
use Drupal\server_general\ThemeTrait\CountryThemeTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Country Info" paragraph plugin.
 *
 * @EntityViewBuilder(
 *   id = "paragraph.country_info",
 *   label = @Translation("Paragraph - Country Info"),
 *   description = "Paragraph view builder for 'Country Info' bundle."
 * )
 */
class ParagraphCountryInfo extends EntityViewBuilderPluginAbstract {

  use CountryThemeTrait;
  use ProcessedTextBuilderTrait;

  /**
   * The OG context service.
   *
   * @var \Drupal\og\OgContextInterface
   */
  protected $ogContext;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->ogContext = $container->get('og.context');

    return $plugin;
  }

  /**
   * Build full view mode.
   *
   * @param array $build
   *   The existing build.
   * @param \Drupal\paragraphs\ParagraphInterface $entity
   *   The entity.
   *
   * @return array
   *   Render array.
   */
  public function buildFull(array $build, ParagraphInterface $entity): array {
    // Get the current group context (resolved by hostname).
    $current_group = $this->ogContext->getGroup();

    if (!$current_group) {
      // No group context, don't render.
      return $build;
    }

    if (!$current_group instanceof NodeInterface || $current_group->bundle() !== 'country') {
      // Not a Country group, don't render.
      return $build;
    }

    // Get country information.
    $country_code = $this->getTextFieldValue($current_group, 'field_country_code');
    $title = $current_group->label();
    $body = $this->buildProcessedText($current_group, 'body');

    $element = $this->buildElementCountry($country_code, $title, $body);

    // Add cache dependency for the Country node.
    $cache = CacheableMetadata::createFromRenderArray($element);
    $cache->addCacheableDependency($current_group);
    $cache->addCacheContexts(['url.site']);
    $cache->applyTo($element);

    $build[] = $element;

    return $build;
  }

}
