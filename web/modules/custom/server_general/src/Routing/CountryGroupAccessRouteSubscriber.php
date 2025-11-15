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
    $country = $this->resolveCountry($node);

    if (!$country) {
      return;
    }

    $hostnames = $country->get('field_hostnames');
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
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   The access result.
   */
  public function access(AccountInterface $account, NodeInterface $node): ?AccessResultInterface {
    // @todo: Check if needed.
    $is_admin_route = $this->adminContext->isAdminRoute();


    $country = $this->ogContext->getGroup();
    // No country context resolved.
    if (empty($country)) {
      return AccessResult::neutral();
    }

    $country_access = $country->access('view', $account, TRUE);
    if (!$country_access->isAllowed()) {
      return AccessResult::forbidden('User has no access to country')
        // @todo: per user
        ->addCacheableDependency($country_access)
        ->addCacheableDependency($node);
    }

    if ($country->id() !== $node->id()) {
      // Check if the user has access to the node itself.
      $node_access = $node->access('view', $account, TRUE);
      if (!$node_access->isAllowed()) {
        return AccessResult::forbidden('User has no access to viewed node')
          // @todo: per user
          ->addCacheableDependency($country_access)
          ->addCacheableDependency($node);
      }

      if (!$node->isPublished()) {
        $this->messenger->addWarning($this->t('You are viewing unpublished content: @title', [
          '@title' => $node->label(),
        ]));
      }
    }

    // @todo: Move to helper method.
    if (!$country->isPublished()) {
      $this->messenger->addWarning($this->t('You are viewing content on an unpublished country: @title', [
        '@title' => $country->label(),
      ]));
    }

    return $this->redirectToCorrectHostname($node);
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
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   Returns forbidden if language is not allowed, null if check should be
   *   skipped or language is allowed.
   */
  protected function checkLanguageAccess(NodeInterface $node, NodeInterface $country_group, AccountInterface $account): ?AccessResultInterface {
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
    if (!$this->membershipManager->isMember($country_group, $account->id())) {
      // Language is not available for regular users.
      return AccessResult::forbidden('This language is not available for the current country context')
        ->addCacheableDependency($node)
        ->addCacheableDependency($country_group)
        ->addCacheContexts(['url.site', 'languages:language_interface', 'user.permissions']);
    }

    $language = $node->language();
    $this->messenger->addWarning($this->t('You are viewing content in a language (@language) that is not enabled for this country.', [
      '@language' => $language->getName(),
    ]));


    return NULL;
  }

}
