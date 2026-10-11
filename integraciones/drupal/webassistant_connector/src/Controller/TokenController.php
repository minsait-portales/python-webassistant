<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webassistant_connector\Identity\WidgetTokenManager;
use Drupal\webassistant_connector\Platform\PlatformException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Returns widget tokens (route webassistant_connector.token, section 5).
 *
 * Login, the "use webassistant connector" permission and the X-CSRF-Token
 * header are enforced by the route requirements.
 */
final class TokenController extends ControllerBase {

  /**
   * Headers of every response: the token must never be cached.
   */
  private const NO_STORE_HEADERS = [
    'Cache-Control' => 'no-store, private',
    'Pragma' => 'no-cache',
    'X-Robots-Tag' => 'noindex',
  ];

  /**
   * Constructs a TokenController.
   *
   * @param \Drupal\webassistant_connector\Identity\WidgetTokenManager $tokenManager
   *   The widget token manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly WidgetTokenManager $tokenManager,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Issues a token for the current user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   200 {"token": "...", "expires_at": "<ISO 8601>"} with Cache-Control:
   *   no-store, private and Pragma: no-cache; 403 when identity is disabled;
   *   429 on flood; 503 {"error": "unavailable"} when the platform fails
   *   (logged with the correlation ID, no details to the browser).
   */
  public function token(Request $request): JsonResponse {
    try {
      $token = $this->tokenManager->getToken($this->currentUser(), $request);
    }
    catch (AccessDeniedHttpException) {
      return self::json(['error' => 'forbidden'], 403);
    }
    catch (TooManyRequestsHttpException $e) {
      return self::json(['error' => 'too_many_requests'], 429, $e->getHeaders());
    }
    catch (PlatformException $e) {
      $this->logger->error('The WebAssistant platform did not issue a widget token: @code (HTTP @status, correlation ID @correlation_id). @message', [
        '@code' => $e->getErrorCode(),
        '@status' => $e->getStatus() ?? '-',
        '@correlation_id' => $e->getCorrelationId() ?? '-',
        '@message' => $e->getMessage(),
      ]);
      $headers = [];
      if ($e->getRetryAfter() !== NULL && $e->isRetryable()) {
        $headers['Retry-After'] = (string) $e->getRetryAfter();
      }
      return self::json(['error' => 'unavailable'], 503, $headers);
    }
    catch (\InvalidArgumentException $e) {
      // An identity value out of the platform limits (e.g. set by a
      // WidgetTokenEvent subscriber).
      $this->logger->error('The widget token request is invalid: @message', ['@message' => $e->getMessage()]);
      return self::json(['error' => 'unavailable'], 503);
    }

    return self::json([
      'token' => $token->accessToken,
      'expires_at' => $token->getExpiresAtIso(),
    ], 200);
  }

  /**
   * Builds a non-cacheable JSON response.
   *
   * @param array<string, string> $data
   *   The body.
   * @param int $status
   *   The HTTP status.
   * @param array<string, mixed> $headers
   *   Extra headers.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  private static function json(array $data, int $status, array $headers = []): JsonResponse {
    return new JsonResponse($data, $status, self::NO_STORE_HEADERS + $headers);
  }

}
