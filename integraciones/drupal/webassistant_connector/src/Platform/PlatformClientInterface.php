<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Platform;

/**
 * Server-to-server client of the WebAssistant API v1 (design section 4).
 *
 * Every method throws PlatformException on network errors, unexpected
 * responses and non-success HTTP statuses (except where documented). The
 * client never logs or exposes secrets nor full response bodies.
 *
 * Service: webassistant_connector.platform_client.
 */
interface PlatformClientInterface {

  /**
   * Issues a widget token for a Drupal user (POST /v1/token, widget_token).
   *
   * The body carries client_id/client_secret, site_id and the request fields
   * (see WidgetTokenRequest::toArray()).
   *
   * @param \Drupal\webassistant_connector\Platform\WidgetTokenRequest $request
   *   The token request.
   *
   * @return \Drupal\webassistant_connector\Platform\TokenResult
   *   The issued token.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function issueWidgetToken(WidgetTokenRequest $request): TokenResult;

  /**
   * Returns a server token (POST /v1/token, client_credentials grant).
   *
   * The token is cached in memory until 30 seconds before it expires.
   *
   * @return \Drupal\webassistant_connector\Platform\TokenResult
   *   The server token.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function serverToken(): TokenResult;

  /**
   * Sends documents to POST /v1/ingest/documents (202, partial results).
   *
   * @param array<int, array<string, mixed>> $documents
   *   Canonical documents (CanonicalDocument::toArray()), at most 100.
   *
   * @return array<int, array<string, mixed>>
   *   The "items" list of the response: one item per document with
   *   source_id, knowledge_base, status (queued, processing, ready, failed,
   *   deleted, rejected), operation and, for rejected items, errors.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function ingest(array $documents): array;

  /**
   * Deletes a document (DELETE /v1/ingest/documents/{kb}/{source_id}).
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $sourceId
   *   The remote source_id.
   *
   * @return array<string, mixed>
   *   The response body ({source_id, knowledge_base, status, operation,
   *   document_id}). An unknown document returns operation "unchanged".
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function deleteDocument(string $knowledgeBase, string $sourceId): array;

  /**
   * Replaces the ACL of a document (PUT /v1/ingest/acl/{kb}/{source_id}).
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $sourceId
   *   The remote source_id.
   * @param list<string> $acl
   *   The principals (public, authenticated, role:x, user:x, group:x).
   *
   * @return array<string, mixed>
   *   The response body.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function updateAcl(string $knowledgeBase, string $sourceId, array $acl): array;

  /**
   * Reads a document (GET /v1/ingest/documents/{kb}/{source_id}).
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $sourceId
   *   The remote source_id.
   *
   * @return array<string, mixed>|null
   *   The document view, or NULL if the API answers 404.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function getDocument(string $knowledgeBase, string $sourceId): ?array;

  /**
   * Reads one page of the remote inventory (GET /v1/ingest/inventory/{kb}).
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string|null $cursor
   *   Exclusive cursor (the next_cursor of the previous page).
   * @param int $limit
   *   Page size (1..1000).
   *
   * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
   *   Items with source_id, version, content_hash, status and acl.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function inventory(string $knowledgeBase, ?string $cursor = NULL, int $limit = 500): array;

  /**
   * Searches the knowledge bases (POST /v1/search).
   *
   * @param array<string, mixed> $query
   *   The search body (query, knowledge_bases, site_id, locale, principals,
   *   limit, min_score).
   *
   * @return array{items: list<array<string, mixed>>}
   *   The search results.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function search(array $query): array;

  /**
   * Checks the platform readiness (GET /health/ready).
   *
   * @return array<string, mixed>
   *   The health body.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function health(): array;

  /**
   * Reads the public bootstrap of the site (GET /v1/bootstrap?site_id=...).
   *
   * @param string $origin
   *   The Origin header to send (scheme://host[:port] of the Drupal site).
   *
   * @return array<string, mixed>
   *   The bootstrap body.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function bootstrap(string $origin): array;

}
