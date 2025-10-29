<?php

declare(strict_types=1);

namespace Drupal\server_general\Plugin\EntityViewBuilder;

use Drupal\node\NodeInterface;
use Drupal\server_general\EntityViewBuilder\NodeViewBuilderAbstract;
use Drupal\server_general\ThemeTrait\CountryThemeTrait;

/**
 * The "Node Country" plugin.
 *
 * @EntityViewBuilder(
 *   id = "node.country",
 *   label = @Translation("Node - Country"),
 *   description = "Node view builder for Country bundle."
 * )
 */
class NodeCountry extends NodeViewBuilderAbstract {

  use CountryThemeTrait;

  /**
   * Build full view mode.
   *
   * @param array $build
   *   The existing build.
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return array
   *   Render array.
   */
  public function buildFull(array $build, NodeInterface $entity) {
    $country_code = $this->getTextFieldValue($entity, 'field_country_code');
    $title = $entity->label();
    $body = $this->buildProcessedText($entity, 'body');

    // Get news items for this Country.
    $news = $this->buildCountryNews($entity);

    $element = $this->buildElementCountry($country_code, $title, $body, $news);

    $build[] = $element;

    return $build;
  }

  /**
   * Build news items for the Country.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The Country node.
   *
   * @return array
   *   Render array of news teasers.
   */
  protected function buildCountryNews(NodeInterface $entity): array {
    // Query News nodes that reference this Country via og_audience.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'news')
      ->condition('status', 1)
      ->condition('og_audience', $entity->id())
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, 10);

    $nids = $query->execute();

    if (empty($nids)) {
      return [];
    }

    // Load the news nodes.
    $news_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    $items = $this->buildEntities($news_nodes, 'teaser');
    // Build the news teasers element.
    // @todo Add t()
    return $this->buildElementNewsTeasers(('Latest News'), [], $items);
  }

}
