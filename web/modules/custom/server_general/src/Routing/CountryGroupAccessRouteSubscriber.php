<?php

declare(strict_types=1);

namespace Drupal\server_general\Routing;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
use Drupal\og\OgMembershipInterface;
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
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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

    return $this->userHasActiveMembership($account, $group);
  }

  /**
   * Redirect to the correct hostname if the node belongs to a different group.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being accessed.
   */
  protected function redirectToCorrectHostname(NodeInterface $node): void {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    $current_hostname = $request->getHost();
    $target_group = $this->resolveTargetGroup($node);

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
    $request = $this->requestStack->getCurrentRequest();
    $route_name = $request ? $request->attributes->get('_route') : NULL;
    $is_node_route = $this->isNodeRoute($route_name);
    $is_admin_route = $this->adminContext->isAdminRoute();
    $context_group = $this->ogContext->getGroup();
    $target_group = $this->resolveTargetGroup($node);
    $node_access_result = $this->evaluateNodeAccess($node, $account, $is_node_route);

    if ($node_access_result instanceof AccessResultInterface && !$node_access_result->isAllowed()) {
      return $this->denyByNodeAccess($node_access_result, $node);
    }

    if ($result = $this->allowWhenContextIsNotNode($context_group, $node)) {
      return $result;
    }

    if ($denied = $this->denyOnUnpublishedContextHostname($context_group, $target_group, $account, $is_admin_route)) {
      return $denied;
    }

    if (!$target_group instanceof NodeInterface) {
      return $this->accessWithoutTargetGroup($context_group, $node, $is_admin_route);
    }

    $result = $this->evaluateGroupedNodeAccess($node, $target_group, $account, $is_admin_route);

    if ($node_access_result instanceof AccessResultInterface) {
      $result->addCacheableDependency($node_access_result);
    }

    if ($result->isAllowed() && $this->shouldRedirect($context_group, $target_group, $is_node_route, $is_admin_route)) {
      $this->redirectToCorrectHostname($node);
    }

    return $result;
  }

  /**
   * Evaluates access for nodes attached to a Country group.
   */
  protected function evaluateGroupedNodeAccess(NodeInterface $node, NodeInterface $country_group, AccountInterface $account, bool $is_admin_route): AccessResultInterface {
    if ($this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle())) {
      return $this->checkGroupNodeAccess($node, $country_group, $account, $is_admin_route);
    }

    if ($this->groupTypeManager->isGroupContent($node->getEntityTypeId(), $node->bundle())) {
      return $this->checkGroupContentAccess($node, $country_group, $account, $is_admin_route);
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheableDependency($country_group)
      ->addCacheContexts(['url.site', 'languages:language_interface']);
  }

  /**
   * Checks access for Country group nodes.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Country node being accessed.
   * @param \Drupal\node\NodeInterface $country_group
   *   The Country group associated with the node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param bool $is_admin_route
   *   Whether the current route is an admin route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkGroupNodeAccess(NodeInterface $node, NodeInterface $country_group, AccountInterface $account, bool $is_admin_route): AccessResultInterface {
    if ($is_admin_route) {
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheableDependency($country_group)
        ->addCacheContexts(['url.site']);
    }

    if (!$country_group->isPublished()) {
      if (!$this->isPrivilegedUser($account, $country_group)) {
        return AccessResult::forbidden('Cannot access unpublished country')
          ->addCacheableDependency($node)
          ->addCacheableDependency($country_group)
          ->addCacheContexts(['url.site', 'user.permissions']);
      }

      $this->messenger->addWarning($this->t('You are viewing content on an unpublished country: @title', [
        '@title' => $country_group->label(),
      ]));
    }

    $language_access = $this->checkLanguageAccess($node, $country_group, $account, $is_admin_route);
    if ($language_access) {
      return $language_access;
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheableDependency($country_group)
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
   * @param \Drupal\node\NodeInterface $country_group
   *   The Country group context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param bool $is_admin_route
   *   Whether the current route is an admin route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkGroupContentAccess(NodeInterface $node, NodeInterface $country_group, AccountInterface $account, bool $is_admin_route): AccessResultInterface {
    // On admin routes, allow access (they already have permission to be there).
    if ($is_admin_route) {
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->addCacheableDependency($country_group)
        ->addCacheContexts(['url.site']);
    }

    if (!$this->nodeBelongsToGroup($node, $country_group)) {
      return AccessResult::forbidden('Content does not belong to the current group context')
        ->addCacheableDependency($node)
        ->addCacheContexts(['url.site']);
    }

    // If the country group is unpublished, only allow privileged users.
    if (!$country_group->isPublished() && !$this->isPrivilegedUser($account, $country_group)) {
      return AccessResult::forbidden('Cannot access content of unpublished country')
        ->addCacheableDependency($node)
        ->addCacheableDependency($country_group)
        ->addCacheContexts(['url.site', 'user.permissions']);
    }

    // Show warning if viewing content on unpublished country.
    if (!$is_admin_route && !$country_group->isPublished()) {
      $this->messenger->addWarning($this->t('You are viewing content on an unpublished country: @title', [
        '@title' => $country_group->label(),
      ]));
    }

    // Show warning for unpublished content.
    if (!$is_admin_route && !$node->isPublished()) {
      $this->messenger->addWarning($this->t('You are viewing unpublished content: @title', [
        '@title' => $node->label(),
      ]));
    }

    // Check language access.
    $language_access = $this->checkLanguageAccess($node, $country_group, $account, $is_admin_route);
    if ($language_access) {
      return $language_access;
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheableDependency($country_group)
      ->addCacheContexts(['url.site', 'languages:language_interface']);
  }

  /**
   * Determines whether a redirect to the correct hostname is required.
   */
  protected function shouldRedirect(?NodeInterface $context_group, NodeInterface $target_group, bool $is_node_route, bool $is_admin_route): bool {
    if ($is_admin_route || !$is_node_route) {
      return FALSE;
    }

    if (!$context_group instanceof NodeInterface) {
      return TRUE;
    }

    return $context_group->id() !== $target_group->id();
  }

  /**
   * Indicates if the given route name is one of the monitored node routes.
   */
  protected function isNodeRoute(?string $route_name): bool {
    return $route_name && in_array($route_name, self::NODE_ROUTES, TRUE);
  }

  /**
   * Checks if the node belongs to the provided Country group.
   */
  protected function nodeBelongsToGroup(NodeInterface $node, NodeInterface $country_group): bool {
    if (!$node->hasField('og_reference')) {
      return FALSE;
    }

    return $node->get('og_reference')->target_id == $country_group->id();
  }

  /**
   * Evaluates and caches node access when needed.
   */
  protected function evaluateNodeAccess(NodeInterface $node, AccountInterface $account, bool $is_node_route): ?AccessResultInterface {
    if (!$is_node_route) {
      return NULL;
    }

    $is_group_node = $this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle());
    $is_group_content = $this->groupTypeManager->isGroupContent($node->getEntityTypeId(), $node->bundle());

    if (!$is_group_node && !$is_group_content) {
      return NULL;
    }

    return $node->access('view', $account, TRUE);
  }

  /**
   * Builds a forbidden result when node access denies the request.
   */
  protected function denyByNodeAccess(AccessResultInterface $node_access_result, NodeInterface $node): AccessResultInterface {
    return AccessResult::forbidden('Access denied by node access')
      ->addCacheableDependency($node_access_result)
      ->addCacheableDependency($node);
  }

  /**
   * Allows access when the group context is not a node.
   */
  protected function allowWhenContextIsNotNode(mixed $context_group, NodeInterface $node): ?AccessResultInterface {
    if (!$context_group || $context_group instanceof NodeInterface) {
      return NULL;
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheContexts(['url.site', 'languages:language_interface']);
  }

  /**
   * Denies access when browsing another unpublished country hostname.
   */
  protected function denyOnUnpublishedContextHostname(?NodeInterface $context_group, ?NodeInterface $target_group, AccountInterface $account, bool $is_admin_route): ?AccessResultInterface {
    if (!$context_group instanceof NodeInterface) {
      return NULL;
    }

    if ($is_admin_route || $context_group->isPublished()) {
      return NULL;
    }

    if ($this->isSameGroupContext($context_group, $target_group)) {
      return NULL;
    }

    if ($this->isPrivilegedUser($account, $context_group)) {
      return NULL;
    }

    return AccessResult::forbidden('Cannot access content on unpublished country hostname')
      ->addCacheableDependency($context_group)
      ->addCacheContexts(['url.site', 'user.permissions']);
  }

  /**
   * Builds the access result when no target group was resolved.
   */
  protected function accessWithoutTargetGroup(?NodeInterface $context_group, NodeInterface $node, bool $is_admin_route): AccessResultInterface {
    if ($context_group instanceof NodeInterface && !$is_admin_route && !$context_group->isPublished()) {
      $this->messenger->addWarning($this->t('You are viewing content on an unpublished country: @title', [
        '@title' => $context_group->label(),
      ]));
    }

    $result = AccessResult::allowed()
      ->addCacheableDependency($node)
      ->addCacheContexts(['url.site', 'languages:language_interface']);

    if ($context_group instanceof NodeInterface) {
      $result->addCacheableDependency($context_group);
    }

    return $result;
  }

  /**
   * Indicates if both context and target reference the same node.
   */
  protected function isSameGroupContext(?NodeInterface $context_group, ?NodeInterface $target_group): bool {
    if (!$context_group instanceof NodeInterface) {
      return FALSE;
    }

    if (!$target_group instanceof NodeInterface) {
      return FALSE;
    }

    return $context_group->id() === $target_group->id();
  }

  /**
   * Determines the target Country group for the provided node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being accessed.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The resolved Country group or NULL if not applicable.
   */
  protected function resolveTargetGroup(NodeInterface $node): ?NodeInterface {
    // Country nodes are already the target group.
    if ($this->groupTypeManager->isGroup($node->getEntityTypeId(), $node->bundle())) {
      return $node;
    }

    // For group content, extract the first associated Country group.
    if ($this->groupAudienceHelper->hasGroupAudienceField($node->getEntityTypeId(), $node->bundle())) {
      $content_groups = $this->membershipManager->getGroups($node);
      if (!empty($content_groups['node'])) {
        $target_group = reset($content_groups['node']);
        if ($target_group instanceof NodeInterface) {
          return $target_group;
        }
      }
    }

    return NULL;
  }

  /**
   * Determines whether the user has an active OG membership on the group.
   */
  protected function userHasActiveMembership(AccountInterface $account, NodeInterface $group): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }

    $query = $this->entityTypeManager
      ->getStorage('og_membership')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $account->id())
      ->condition('entity_type', $group->getEntityTypeId())
      ->condition('entity_id', $group->id())
      ->condition('state', OgMembershipInterface::STATE_ACTIVE)
      ->range(0, 1);

    return (bool) $query->count()->execute();
  }

}
