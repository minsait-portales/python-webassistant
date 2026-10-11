<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\WebAssistant\HostAction;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\HostActionPluginBase;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;

/**
 * Opens the user profile form (design section 7).
 *
 * No params. Authenticated with access: redirect to entity.user.edit_form;
 * anonymous: redirect to the login form with a destination. The result data
 * carries no user identifiers (the platform stores it).
 */
#[HostAction(
  id: 'user.profile',
  label: new TranslatableMarkup('User profile'),
  description: new TranslatableMarkup('Redirects to the profile form of the current user.'),
  permission: 'use webassistant profile actions',
)]
final class UserProfile extends HostActionPluginBase {

  /**
   * Constructs a UserProfile plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $this->assertAllowedParams($request->params, []);

    if ($account->isAnonymous()) {
      $destination = Url::fromRoute('user.page')->toString(TRUE)->getGeneratedUrl();
      $url = Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]])
        ->toString(TRUE)
        ->getGeneratedUrl();
      return HostActionResult::succeeded(
        ['login_required' => TRUE],
        $this->t('Please log in to see and edit your profile.'),
        $url,
      );
    }

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface || !$user->access('update', $account)) {
      throw $this->failure($this->t('You are not allowed to edit your profile.'));
    }
    $url = $user->toUrl('edit-form')->toString(TRUE)->getGeneratedUrl();
    return HostActionResult::succeeded(
      ['login_required' => FALSE],
      $this->t('Opening your profile.'),
      $url,
    );
  }

}
