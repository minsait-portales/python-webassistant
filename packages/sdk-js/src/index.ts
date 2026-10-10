/**
 * SDK TypeScript de WebAssistant v1 (CG-09.7).
 *
 * - `WebAssistantClient`: cliente del navegador (widget) con tokens temporales y streaming SSE.
 * - `PortalClient`: cliente server-to-server para portales (tokens de widget, ingesta y búsqueda).
 * - `AdminClient`: cliente de la consola de administración.
 */
export { AdminClient, ApiError, PortalClient, WebAssistantClient } from './client';
export type { FetchLike, PortalClientOptions, TokenSource, WidgetClientOptions } from './client';
export { readSse, SseParser } from './sse';
export type { SseMessage } from './sse';
export type * from './types';
export type { components, paths } from './openapi';
