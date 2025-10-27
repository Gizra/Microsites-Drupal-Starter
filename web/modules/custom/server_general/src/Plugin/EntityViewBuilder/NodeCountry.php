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
    $title = $entity->label();
    $body = $this->buildProcessedText($entity, 'body');

    $element = $this->buildElementCountry($title, $body, []);

    $build[] = $element;

    return $build;
  }

}
