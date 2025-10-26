<?php

declare(strict_types=1);

namespace Drupal\server_general\Routing;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\og\GroupTypeManagerInterface;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgContextInterface;
use Drupal\og\OgGroupAudienceHelperInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber for Country group hostname-based access control.
 *
 * Adds access checks to node view routes to ensure content can only be viewed
 * on the correct hostname based on OG group context.
 */
final class CountryGroupAccessRouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs a CountryGroupAccessRouteSubscriber object.
   *
   * @param \Drupal\og\OgContextInterface $ogContext
   *   The OG context service.
   * @param \Drupal\og\OgGroupAudienceHelperInterface $groupAudienceHelper
   *   The OG group audience helper service.
   * @param \Drupal\og\MembershipManagerInterface $membershipManager
   *   The OG membership manager service.
   * @param \Drupal\og\GroupTypeManagerInterface $groupTypeManager
   *   The OG group type manager service.
   */
  public function __construct(
    protected readonly OgContextInterface $ogContext,
    protected readonly OgGroupAudienceHelperInterface $groupAudienceHelper,
    protected readonly MembershipManagerInterface $membershipManager,
    protected readonly GroupTypeManagerInterface $groupTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Add custom access check to node canonical (view) route.
    if ($route = $collection->get('entity.node.canonical')) {
      $route->setRequirement('_custom_access', 'server_general.country_group_access_route_subscriber::access');
    }
  }

  /**
   * Checks if the current node can be accessed based on hostname group context.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param \Drupal\node\NodeInterface $node
   *   The node to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, NodeInterface $node): AccessResultInterface {
    // Get the current group context (resolved by hostname).
    $current_group = $this->ogContext->getGroup();

    if (!$current_group) {
      // No group context, allow access.
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site']);
    }

    // Check if the entity itself is a group (Country).
    if ($this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle())) {
      // Deny access if trying to view a different group than the current
      // context.
      if ($node->id() !== $current_group->id()) {
        return AccessResult::forbidden('Cannot view this group from the current hostname')
          ->addCacheableDependency($node)
          ->addCacheContexts(['url.site']);
      }
      // Group matches current context, allow access.
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site']);
    }

    // Check if this node is group content (e.g., News).
    if (!$this->groupAudienceHelper->hasGroupAudienceField($node->getEntityTypeId(), $node->bundle())) {
      // Not group content, allow access.
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site']);
    }

    // Get all groups this content belongs to.
    $content_groups = $this->membershipManager->getGroups($node);

    // Check if the content belongs to the current group context.
    // Since our groups are nodes (Country), we check the 'node' key directly.
    $belongs_to_current_group = FALSE;
    if (isset($content_groups['node'])) {
      foreach ($content_groups['node'] as $group) {
        if ($group->id() === $current_group->id()) {
          $belongs_to_current_group = TRUE;
          break;
        }
      }
    }

    // If content doesn't belong to current group context, deny access.
    if (!$belongs_to_current_group) {
      return AccessResult::forbidden('Content does not belong to the current group context')
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site']);
    }

    // Content belongs to current group, allow access.
    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheContexts(['url.site']);
  }

}
