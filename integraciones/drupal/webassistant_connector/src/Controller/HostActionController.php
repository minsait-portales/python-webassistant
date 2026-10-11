<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webassistant_connector\HostAction\HostActionDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Executes host actions (route webassistant_connector.host_action, sec. 7).
 *
 * Business results (succeeded or failed) are answered with 200. Protocol
 * errors are answered with a generic JSON body
 * {"status": "failed", "error": "<code>", "message": "<generic text>"} and
 * never contain internal details.
 */
final class HostActionController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * Maximum accepted body size in bytes.
   */
  public const MAX_BODY_BYTES = 65536;

  /**
   * Maximum JSON nesting depth of the body.
   */
  public const MAX_DEPTH = 32;

  /**
   * Constructs a HostActionController.
   *
   * @param \Drupal\webassistant_connector\HostAction\HostActionDispatcher $dispatcher
   *   The host action dispatcher.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly HostActionDispatcher $dispatcher,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Executes a host action.
   *
   * @param string $name
   *   The host action name (route parameter).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; the body is the host action object in JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   200 with HostActionResult::toArray() (no-store); 400 invalid body;
   *   403; 404; 409; 429.
   */
  public function execute(string $name, Request $request): JsonResponse {
    $content = $request->getContent();
    if ($content === '' || strlen($content) > self::MAX_BODY_BYTES) {
      return $this->error(400);
    }
    try {
      $payload = json_decode($content, TRUE, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->error(400);
    }
    if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
      return $this->error(400);
    }

    try {
      $result = $this->dispatcher->dispatch($name, $payload, $this->currentUser, $request);
    }
    catch (HttpExceptionInterface $e) {
      return $this->error($e->getStatusCode(), $e->getHeaders());
    }
    return $this->noStore(new JsonResponse($result->toArray(), 200));
  }

  /**
   * Builds a generic protocol error response.
   *
   * @param int $status
   *   The HTTP status code.
   * @param array<string, mixed> $headers
   *   Extra headers (e.g. Retry-After).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  private function error(int $status, array $headers = []): JsonResponse {
    [$code, $message] = match ($status) {
      400 => ['bad_request', $this->t('The request is not valid.')],
      403 => ['forbidden', $this->t('You are not allowed to perform this action.')],
      404 => ['not_found', $this->t('The action is not available.')],
      409 => ['conflict', $this->t('The action is already in progress.')],
      429 => ['too_many_requests', $this->t('Too many requests. Please try again later.')],
      default => ['error', $this->t('The action could not be completed.')],
    };
    $allowedHeaders = array_intersect_key($headers, ['Retry-After' => TRUE]);
    $response = new JsonResponse([
      'status' => 'failed',
      'error' => $code,
      'message' => (string) $message,
    ], $status >= 400 && $status < 600 ? $status : 500, $allowedHeaders);
    return $this->noStore($response);
  }

  /**
   * Marks a response as private and not storable.
   *
   * @param \Symfony\Component\HttpFoundation\JsonResponse $response
   *   The response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The same response.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
