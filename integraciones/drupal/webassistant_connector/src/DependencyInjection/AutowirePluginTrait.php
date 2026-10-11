<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\DependencyInjection;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\AutowiringFailedException;

/**
 * Autowired create() for container-aware plugins on Drupal 11.2 and 11.3+.
 *
 * Drupal 11.3 autowires plugin constructors in PluginBase::create(), but 11.2
 * does not. Plugins that use this trait (and implement
 * ContainerFactoryPluginInterface) declare a constructor
 * ($configuration, $plugin_id, $plugin_definition, ...services) and get the
 * services resolved by type, or by #[Autowire(service: 'id')].
 */
trait AutowirePluginTrait {

  /**
   * Creates the plugin, autowiring the constructor services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   *
   * @return static
   *   The plugin instance.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $args = [$configuration, $plugin_id, $plugin_definition];
    $constructor = new \ReflectionMethod(static::class, '__construct');
    foreach (array_slice($constructor->getParameters(), 3) as $parameter) {
      $service = ltrim((string) $parameter->getType(), '?');
      foreach ($parameter->getAttributes(Autowire::class) as $attribute) {
        $service = (string) $attribute->newInstance()->value;
      }
      if (!$container->has($service)) {
        if ($parameter->allowsNull()) {
          $args[] = NULL;
          continue;
        }
        throw new AutowiringFailedException($service, sprintf('Cannot autowire service "%s": argument "$%s" of method "%s::__construct()".', $service, $parameter->getName(), static::class));
      }
      $args[] = $container->get($service);
    }
    // @phpstan-ignore new.static
    return new static(...$args);
  }

}
