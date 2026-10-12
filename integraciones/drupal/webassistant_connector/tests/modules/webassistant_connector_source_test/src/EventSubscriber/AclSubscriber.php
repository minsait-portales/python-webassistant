<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_source_test\EventSubscriber;

use Drupal\webassistant_connector\Event\AclAlterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds principals to the ACL of nodes whose title contains "[group]".
 *
 * Also adds an invalid principal, which the AclResolver must drop.
 */
final class AclSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AclAlterEvent::NAME => 'onAclAlter'];
  }

  /**
   * Adds group:42 and an invalid principal.
   *
   * @param \Drupal\webassistant_connector\Event\AclAlterEvent $event
   *   The event.
   */
  public function onAclAlter(AclAlterEvent $event): void {
    if (str_contains((string) $event->getEntity()->label(), '[group]')) {
      $event->addPrincipal('group:42');
      $event->addPrincipal('role:not valid!');
    }
  }

}
