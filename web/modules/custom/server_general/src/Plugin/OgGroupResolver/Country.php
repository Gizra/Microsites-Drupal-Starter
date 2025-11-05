<?php

declare(strict_types=1);

namespace Drupal\server_general\Plugin\OgGroupResolver;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\og\Attribute\OgGroupResolver;
use Drupal\og\OgResolvedGroupCollectionInterface;
use Drupal\og\OgRouteGroupResolverBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the group from the current hostname.
 *
 * This plugin inspects the current request hostname and checks if it matches
 * any Country entity's field_hostnames values.
 */
#[OgGroupResolver(
  id: 'country_hostname',
  label: new TranslatableMarkup('Country from hostname'),
  description: new TranslatableMarkup('Resolves the Country group based on the current hostname.')
)]
class Country extends OgRouteGroupResolverBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->requestStack = $container->get('request_stack');
    $plugin->currentUser = $container->get('current_user');

    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(OgResolvedGroupCollectionInterface $collection) {
    // Get the current hostname from the request.
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    $hostname = $request->getHost();

    // Validate hostname format.
    if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN)) {
      // Only log warning for non-empty hostnames (empty means CLI context).
      if (!empty($hostname)) {
        \Drupal::logger('server_general')->warning('Invalid hostname format: @hostname', ['@hostname' => $hostname]);
      }
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');

    // Query Country nodes that have the current hostname in field_hostnames.
    $query = $storage->getQuery()
      ->condition('type', 'country')
      ->condition('field_hostnames', $hostname)
      ->accessCheck(TRUE)
      ->range(0, 1);

    // Only filter by published status if user doesn't have permission to view
    // unpublished content. This allows editors to work on unpublished countries.
    if (!$this->currentUser->hasPermission('bypass node access') && !$this->currentUser->hasPermission('administer nodes')) {
      $query->condition('status', NodeInterface::PUBLISHED);
    }

    $nids = $query->execute();

    if (empty($nids)) {
      return;
    }

    // Load the Country node.
    $nid = reset($nids);
    /** @var \Drupal\node\NodeInterface $country */
    $country = $storage->load($nid);

    if (!$country) {
      return;
    }

    // Verify it's actually a group.
    if ($this->groupTypeManager->isGroup($country->getEntityTypeId(), $country->bundle())) {
      // Add the group with the 'url.site' cache context since it depends on
      // the hostname.
      $collection->addGroup($country, ['url.site']);

      // Since we found a specific Country based on the hostname, we can be
      // certain this is the correct group context and stop propagation.
      $this->stopPropagation();
    }
  }

}
