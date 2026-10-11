<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Dispatched after the ACL of a document is computed (amendment B4).
 *
 * Subscribers may add principals that Drupal core cannot compute (e.g. the
 * Group module adds group:<id>) or remove principals. Principals must match
 * ^(public|authenticated|(role|user|group):[A-Za-z0-9_.@-]+)$; invalid ones
 * are dropped (and logged) by the AclResolver, never widened. An empty ACL
 * means the document is not indexable.
 */
final class AclAlterEvent extends Event {

  /**
   * Event name.
   */
  public const NAME = 'webassistant_connector.acl_alter';

  /**
   * Principals.
   *
   * @var list<string>
   */
  private array $acl;

  /**
   * Constructs an AclAlterEvent.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, in the translation being indexed.
   * @param list<string> $acl
   *   The computed principals.
   */
  public function __construct(
    private readonly SourceInterface $source,
    private readonly EntityInterface $entity,
    array $acl,
  ) {
    $this->setAcl($acl);
  }

  /**
   * Returns the source.
   */
  public function getSource(): SourceInterface {
    return $this->source;
  }

  /**
   * Returns the entity (in the translation being indexed).
   */
  public function getEntity(): EntityInterface {
    return $this->entity;
  }

  /**
   * Returns the principals.
   *
   * @return list<string>
   *   The principals.
   */
  public function getAcl(): array {
    return $this->acl;
  }

  /**
   * Replaces the principals.
   *
   * @param list<string> $acl
   *   The principals.
   *
   * @return $this
   */
  public function setAcl(array $acl): static {
    $this->acl = array_values(array_unique(array_map('strval', $acl)));
    return $this;
  }

  /**
   * Adds a principal (e.g. group:42).
   *
   * @param string $principal
   *   The principal.
   *
   * @return $this
   */
  public function addPrincipal(string $principal): static {
    if (!in_array($principal, $this->acl, TRUE)) {
      $this->acl[] = $principal;
    }
    return $this;
  }

  /**
   * Removes a principal.
   *
   * @param string $principal
   *   The principal.
   *
   * @return $this
   */
  public function removePrincipal(string $principal): static {
    $this->acl = array_values(array_filter($this->acl, static fn (string $item): bool => $item !== $principal));
    return $this;
  }

}
