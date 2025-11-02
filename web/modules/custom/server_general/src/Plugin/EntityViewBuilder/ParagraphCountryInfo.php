<?php

declare(strict_types=1);

namespace Drupal\server_general\Plugin\EntityViewBuilder;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\pluggable_entity_view_builder\EntityViewBuilderPluginAbstract;
use Drupal\server_general\ProcessedTextBuilderTrait;
use Drupal\server_general\ThemeTrait\CountryThemeTrait;
use Drupal\server_general\ThemeTrait\Enum\FontSizeEnum;
use Drupal\server_general\ThemeTrait\LinkThemeTrait;
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
  use LinkThemeTrait;
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
      // No group context, just indicate that user needs to navigate into the
      // correct domain.
      $elements = [];

      $element = [
        $this->wrapTextResponsiveFontSize($this->t('News Placeholder'), FontSizeEnum::ThreeXl),
        $this->wrapTextItalic($this->t('Navigate to the a country site to see this information')),
      ];

      $elements[] = $this->wrapContainerVerticalSpacing($element);

      // Add list of all country links.
      $country_links = $this->getAllCountryUrls();
      if (!empty($country_links)) {
        $links_element = $this->wrapContainerVerticalSpacing($country_links);

        // Add cache tags for all country nodes.
        $cache = CacheableMetadata::createFromRenderArray($links_element);
        $cache->addCacheTags(['node_list:country']);
        $cache->applyTo($links_element);

        $elements[] = $links_element;
      }

      $elements = $this->wrapContainerVerticalSpacing($elements);
      $elements = $this->wrapContainerWide($elements);

      $cache = CacheableMetadata::createFromRenderArray($elements);
      $cache->addCacheableDependency($current_group);
      $cache->addCacheContexts(['url.site']);
      $cache->applyTo($elements);

      $build[] = $elements;

      return $build;
    }

    if ($current_group->getEntityTypeId() !== 'node' || $current_group->bundle() !== 'country') {
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

  /**
   * Get all country URLs.
   *
   * Returns an array of country URLs, using the first hostname from
   * for each published Country node.
   *
   * @return array
   *   Array of render arrays for country links, keyed by country title.
   */
  protected function getAllCountryUrls(): array {
    $storage = $this->entityTypeManager->getStorage('node');

    // Query all published Country nodes.
    $query = $storage->getQuery()
      ->condition('type', 'country')
      ->condition('status', NodeInterface::PUBLISHED)
      ->accessCheck(TRUE)
      ->sort('title', 'ASC');

    $nids = $query->execute();

    if (empty($nids)) {
      return [];
    }

    $countries = $storage->loadMultiple($nids);
    $country_links = [];

    foreach ($countries as $country) {
      // Get the first hostname from field_host_names.
      if ($country->get('field_hostnames')->isEmpty()) {
        continue;
      }

      $hostname = $country->get('field_hostnames')->first()->value;
      if (empty($hostname)) {
        continue;
      }

      // If hostname has `ddev.site` then add 4443 port for local dev.
      if (str_ends_with($hostname, 'ddev.site')) {
        $hostname .= ':4443';
      }

      // Build URL using the hostname.
      $url = Url::fromUri('https://' . $hostname);

      // Build link element.
      $country_links[] = $this->buildLink($country->label(), $url);
    }

    return $country_links;
  }

}
