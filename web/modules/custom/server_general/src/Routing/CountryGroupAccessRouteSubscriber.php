<?php

declare(strict_types=1);

namespace Drupal\server_general\Routing;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
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

  use StringTranslationTrait;

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
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Routing\AdminContext $adminContext
   *   The admin context service.
   */
  public function __construct(
    protected readonly OgContextInterface $ogContext,
    protected readonly OgGroupAudienceHelperInterface $groupAudienceHelper,
    protected readonly MembershipManagerInterface $membershipManager,
    protected readonly GroupTypeManagerInterface $groupTypeManager,
    protected readonly RequestStack $requestStack,
    protected readonly MessengerInterface $messenger,
    protected readonly AdminContext $adminContext,
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
   * Checks if user is privileged to bypass group restrictions.
   *
   * A user is considered privileged if they have 'bypass node access'
   * permission or if they are a member of the given group.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param \Drupal\node\NodeInterface $group
   *   The group entity to check membership for.
   *
   * @return bool
   *   TRUE if user is privileged, FALSE otherwise.
   */
  protected function isPrivilegedUser(AccountInterface $account, NodeInterface $group): bool {
    // Check for bypass permission.
    if ($account->hasPermission('bypass node access')) {
      return TRUE;
    }

    // Check if user is a member of the group.
    return $this->membershipManager->isMember($group, $account->id());
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
      if ($current_group && $node->id() === $current_group->id()) {
        return;
      }
      $target_group = $node;
    }
    // Check if the node is group content.
    elseif ($this->groupAudienceHelper->hasGroupAudienceField($node->getEntityTypeId(), $node->bundle())) {
      $content_groups = $this->membershipManager->getGroups($node);
      if (empty($content_groups['node'])) {
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
    $current_group = $this->ogContext->getGroup();
    $request = $this->requestStack->getCurrentRequest();
    $is_admin_route = $this->adminContext->isAdminRoute();
    $route_name = $request ? $request->attributes->get('_route') : NULL;

    // Invalid group type, allow access.
    if ($current_group && !$current_group instanceof NodeInterface) {
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site', 'languages:language_interface']);
    }

    // No group context (main site hostname), allow access to all content.
    if (!$current_group) {
      if ($route_name && in_array($route_name, self::NODE_ROUTES)) {
        $is_group_node = $this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle());
        $is_group_content = $this->groupAudienceHelper->hasGroupAudienceField($node->getEntityTypeId(), $node->bundle());
        if ($is_group_node || $is_group_content) {
          $this->redirectToCorrectHostname($node, NULL);
        }
      }
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site', 'languages:language_interface']);
    }

    // Block access to unpublished country hostnames for non-privileged users.
    if (!$is_admin_route && !$current_group->isPublished() && !$this->isPrivilegedUser($account, $current_group)) {
      return AccessResult::forbidden('Cannot access content on unpublished country hostname')
        ->addCacheableDependency($current_group)
        ->addCacheContexts(['url.site', 'user.permissions']);
    }

    // Handle Country group nodes.
    if ($this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle())) {
      return $this->checkGroupNodeAccess($node, $current_group, $account, $is_admin_route);
    }

    // Handle group content nodes (e.g., News articles).
    if ($this->groupAudienceHelper->hasGroupAudienceField($node->getEntityTypeId(), $node->bundle())) {
      return $this->checkGroupContentAccess($node, $current_group, $account, $request, $is_admin_route);
    }

    // Show warning if viewing content on an unpublished country's hostname.
    // This is for non-group content (like landing pages).
    if (!$is_admin_route && !$current_group->isPublished()) {
      $this->messenger->addWarning($this->t('You are viewing content on an unpublished country: @title', [
        '@title' => $current_group->label(),
      ]));
    }

    // Not group content, allow access.
    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheableDependency($current_group)
      ->addCacheContexts(['url.site', 'languages:language_interface']);
  }

  /**
   * Checks access for Country group nodes.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Country node being accessed.
   * @param \Drupal\node\NodeInterface $current_group
   *   The current group context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param bool $is_admin_route
   *   Whether the current route is an admin route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkGroupNodeAccess(NodeInterface $node, NodeInterface $current_group, AccountInterface $account, bool $is_admin_route): AccessResultInterface {
    // Viewing a different Country than the current context.
    if ($node->id() !== $current_group->id()) {
      // On admin routes, allow access (they already have permission to be
      // there).
      if ($is_admin_route) {
        return AccessResult::allowed()
          ->addCacheableDependency($node)
          ->addCacheableDependency($current_group)
          ->addCacheContexts(['url.site']);
      }

      // On node-specific routes, redirect to correct hostname.
      $request = $this->requestStack->getCurrentRequest();
      $route_name = $request ? $request->attributes->get('_route') : NULL;
      if ($route_name && in_array($route_name, self::NODE_ROUTES)) {
        $this->redirectToCorrectHostname($node, $current_group);
      }

      // Deny access for non-admin routes.
      return AccessResult::forbidden('Cannot view this group from the current hostname')
        ->addCacheableDependency($node)
        ->addCacheableDependency($current_group)
        ->addCacheContexts(['url.site']);
    }

    // Show warning if viewing unpublished country.
    if (!$is_admin_route && !$current_group->isPublished()) {
      // Block access for non-privileged users.
      if (!$this->isPrivilegedUser($account, $current_group)) {
        return AccessResult::forbidden('Cannot access unpublished country')
          ->addCacheableDependency($node)
          ->addCacheableDependency($current_group)
          ->addCacheContexts(['url.site', 'user.permissions']);
      }

      // Show warning for privileged users.
      $this->messenger->addWarning($this->t('You are viewing content on an unpublished country: @title', [
        '@title' => $current_group->label(),
      ]));
    }

    // Check language access.
    $language_access = $this->checkLanguageAccess($node, $current_group, $account, $is_admin_route);
    if ($language_access) {
      return $language_access;
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheableDependency($current_group)
      ->addCacheContexts(['url.site', 'languages:language_interface', 'user.permissions']);
  }

  /**
   * Checks if a node's language is allowed for the given Country group.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to check.
   * @param \Drupal\node\NodeInterface $country_group
   *   The Country group entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param bool $is_admin_route
   *   Whether the current route is an admin route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   Returns forbidden if language is not allowed, null if check should be
   *   skipped or language is allowed.
   */
  protected function checkLanguageAccess(NodeInterface $node, NodeInterface $country_group, AccountInterface $account, bool $is_admin_route): ?AccessResultInterface {
    $allowed_languages = [];
    foreach ($country_group->get('field_languages') as $item) {
      $allowed_languages[] = $item->value;
    }

    if (empty($allowed_languages)) {
      return NULL;
    }

    $node_langcode = $node->language()->getId();

    // Language is allowed for this country.
    if (in_array($node_langcode, $allowed_languages)) {
      return NULL;
    }

    // Language is not enabled but allow privileged users to access.
    if ($this->isPrivilegedUser($account, $country_group)) {
      if (!$is_admin_route) {
        $language = $node->language();
        $this->messenger->addWarning($this->t('You are viewing content in a language (@language) that is not enabled for this country.', [
          '@language' => $language->getName(),
        ]));
      }
      return NULL;
    }

    // Language is not available for regular users.
    return AccessResult::forbidden('This language is not available for the current country context')
      ->addCacheableDependency($node)
      ->addCacheableDependency($country_group)
      ->addCacheContexts(['url.site', 'languages:language_interface', 'user.permissions']);
  }

  /**
   * Checks access for group content nodes.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The group content node being accessed.
   * @param \Drupal\node\NodeInterface $current_group
   *   The current group context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request.
   * @param bool $is_admin_route
   *   Whether the current route is an admin route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkGroupContentAccess(NodeInterface $node, NodeInterface $current_group, AccountInterface $account, $request, bool $is_admin_route): AccessResultInterface {
    $content_groups = $this->membershipManager->getGroups($node);

    // On admin routes, allow access (they already have permission to be there).
    if ($is_admin_route) {
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheableDependency($current_group)
        ->addCacheContexts(['url.site']);
    }

    // On node-specific routes, redirect to correct hostname before access
    // checks.
    $route_name = $request ? $request->attributes->get('_route') : NULL;
    if ($route_name && in_array($route_name, self::NODE_ROUTES)) {
      $this->redirectToCorrectHostname($node, $current_group);
    }

    // Check if content belongs to current group.
    $belongs_to_current_group = FALSE;
    if (isset($content_groups['node'])) {
      foreach ($content_groups['node'] as $group) {
        if ($group->id() === $current_group->id()) {
          $belongs_to_current_group = TRUE;
          break;
        }
      }
    }

    // Content doesn't belong to current group, deny access.
    if (!$belongs_to_current_group) {
      return AccessResult::forbidden('Content does not belong to the current group context')
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site']);
    }

    // If the country group is unpublished, only allow privileged users.
    if (!$current_group->isPublished() && !$this->isPrivilegedUser($account, $current_group)) {
      return AccessResult::forbidden('Cannot access content of unpublished country')
        ->addCacheableDependency($node)
        ->addCacheableDependency($current_group)
        ->addCacheContexts(['url.site', 'user.permissions']);
    }

    // Show warning if viewing content on unpublished country.
    if (!$is_admin_route && !$current_group->isPublished()) {
      $this->messenger->addWarning($this->t('You are viewing content on an unpublished country: @title', [
        '@title' => $current_group->label(),
      ]));
    }

    // Show warning for unpublished content.
    if (!$is_admin_route && !$node->isPublished()) {
      $this->messenger->addWarning($this->t('You are viewing unpublished content: @title', [
        '@title' => $node->label(),
      ]));
    }

    // Check language access.
    $language_access = $this->checkLanguageAccess($node, $current_group, $account, $is_admin_route);
    if ($language_access) {
      return $language_access;
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheableDependency($current_group)
      ->addCacheContexts(['url.site', 'languages:language_interface']);
  }

}
