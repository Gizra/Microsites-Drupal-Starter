<?php

declare(strict_types=1);

namespace Drupal\server_general\Routing;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\og\GroupTypeManagerInterface;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgContextInterface;
use Drupal\og\OgGroupAudienceHelperInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber for Country group hostname-based access control.
 *
 * Adds access checks to node view routes to ensure content can only be viewed
 * on the correct hostname based on OG group context.
 */
final class CountryGroupAccessRouteSubscriber extends RouteSubscriberBase {

  /**
   * Node routes that require group access checks and redirects.
   */
  protected const NODE_ROUTES = [
    'entity.node.canonical',
    'entity.node.edit_form',
    'entity.node.delete_form',
    'entity.node.content_translation_overview',
    'entity.node.content_translation_add',
    'entity.node.content_translation_edit',
    'entity.node.content_translation_delete',
    'entity.node.devel_load',
    'entity.node.devel_render',
    'entity.node.devel_definition',
  ];

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
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack service.
   */
  public function __construct(
    protected readonly OgContextInterface $ogContext,
    protected readonly OgGroupAudienceHelperInterface $groupAudienceHelper,
    protected readonly MembershipManagerInterface $membershipManager,
    protected readonly GroupTypeManagerInterface $groupTypeManager,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Add custom access check to node routes.
    foreach (self::NODE_ROUTES as $route_name) {
      if ($route = $collection->get($route_name)) {
        $route->setRequirement('_custom_access', 'server_general.country_group_access_route_subscriber::access');
      }
    }
  }

  /**
   * Redirect to the correct hostname if the node belongs to a different group.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being accessed.
   * @param ?\Drupal\node\NodeInterface $current_group
   *   The current group context.
   */
  protected function redirectToCorrectHostname(NodeInterface $node, ?NodeInterface $current_group): void {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    $current_hostname = $request->getHost();
    $target_group = NULL;

    // Check if the node itself is a Country group.
    if ($this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle())) {
      // No redirect needed if viewing the same Country as current context.
      if (!$current_group || $node->id() === $current_group->id()) {
        return;
      }
      $target_group = $node;
    }
    // Check if the node is group content.
    elseif ($this->groupAudienceHelper->hasGroupAudienceField($node->getEntityTypeId(), $node->bundle())) {
      $content_groups = $this->membershipManager->getGroups($node);
      if (!isset($content_groups['node']) || empty($content_groups['node'])) {
        return;
      }

      $first_group = reset($content_groups['node']);
      // No redirect needed if content belongs to current group.
      if ($current_group && $first_group->id() === $current_group->id()) {
        return;
      }
      $target_group = $first_group;
    }

    // No target group found.
    if (!$target_group) {
      return;
    }

    // Ensure target group is a Node (Country groups are nodes).
    if (!$target_group instanceof NodeInterface) {
      return;
    }

    $hostnames = $target_group->get('field_hostnames');
    if ($hostnames->isEmpty()) {
      return;
    }

    $correct_hostname = $hostnames->first()->getString();
    // Already on correct hostname.
    if (!$correct_hostname || $correct_hostname === $current_hostname) {
      return;
    }

    // Validate hostname before redirect to prevent open redirects.
    if (!filter_var($correct_hostname, FILTER_VALIDATE_DOMAIN)) {
      \Drupal::logger('server_general')->warning('Invalid redirect hostname: @hostname', ['@hostname' => $correct_hostname]);
      return;
    }

    // Perform redirect.
    $redirect_url = $request->getScheme() . '://' . $correct_hostname;
    if ($request->getPort() && !in_array($request->getPort(), [80, 443])) {
      $redirect_url .= ':' . $request->getPort();
    }
    $redirect_url .= $request->getRequestUri();

    $response = new TrustedRedirectResponse($redirect_url);
    $response->send();
    exit;
  }

  /**
   * Checks if a node's language is allowed for the given Country group.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to check.
   * @param \Drupal\node\NodeInterface $country_group
   *   The Country group entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   Returns forbidden if language is not allowed, null if check should be
   *   skipped or language is allowed.
   */
  protected function checkLanguageAccess(NodeInterface $node, NodeInterface $country_group): ?AccessResultInterface {
    $allowed_languages = [];
    foreach ($country_group->get('field_languages') as $item) {
      $allowed_languages[] = $item->value;
    }

    if (empty($allowed_languages)) {
      return NULL;
    }

    $node_langcode = $node->language()->getId();
    if (!in_array($node_langcode, $allowed_languages)) {
      return AccessResult::forbidden('This language is not available for the current country context')
        ->addCacheableDependency($node)
        ->addCacheableDependency($country_group)
        ->addCacheContexts(['url.site', 'languages:language_interface']);
    }

    return NULL;
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
    // Get the current group context (resolved by hostname or other means).
    $current_group = $this->ogContext->getGroup();
    $request = $this->requestStack->getCurrentRequest();

    // Check if node administrators should be redirected to correct hostname.
    // Only redirect on node-specific routes, not admin listing routes.
    if ($account->hasPermission('administer nodes')) {
      $route_name = $request ? $request->attributes->get('_route') : NULL;

      // Only perform redirect logic on node-specific routes.
      if ($route_name && in_array($route_name, self::NODE_ROUTES)) {
        // Check and perform redirect if needed.
        $this->redirectToCorrectHostname($node, $current_group);
      }

      // Allow access if no redirect happened.
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site', 'languages:language_interface', 'user.permissions']);
    }

    if (!$current_group) {
      // No group context (main site hostname), allow access to all content.
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site', 'languages:language_interface']);
    }

    // Check if the entity itself is a group (Country).
    if ($this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle())) {
      // Deny access if trying to view a different group than the current
      // context.
      if ($node->id() !== $current_group->id()) {
        return AccessResult::forbidden('Cannot view this group from the current hostname')
          ->addCacheableDependency($node)
          ->addCacheableDependency($current_group)
          ->addCacheContexts(['url.site']);
      }
      // Group matches current context, check language access.
      $language_access = $this->checkLanguageAccess($node, $current_group);
      if ($language_access) {
        return $language_access;
      }

      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheableDependency($current_group)
        ->addCacheContexts(['url.site', 'languages:language_interface']);
    }

    // Check if this node is group content (e.g., News).
    if (!$this->groupAudienceHelper->hasGroupAudienceField($node->getEntityTypeId(), $node->bundle())) {
      // Not group content, allow access.
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site', 'languages:language_interface']);
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

    // Check if the node's language is allowed for this Country group.
    $language_access = $this->checkLanguageAccess($node, $current_group);
    if ($language_access) {
      return $language_access;
    }

    // Content belongs to current group and language is allowed, allow access.
    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheableDependency($current_group)
      ->addCacheContexts(['url.site', 'languages:language_interface']);
  }

}
