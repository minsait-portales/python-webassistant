/** Eventos del widget hacia la página anfitriona (contrato widget-events v1). */
import type { WidgetEvent, WidgetEventType } from '@webassistant/sdk';

import type { WebAssistantGlobal } from '../shared';

export class EventBus {
  constructor(
    private readonly instanceId: string,
    private readonly global: WebAssistantGlobal,
  ) {}

  emit<P extends Record<string, unknown>>(
    type: WidgetEventType,
    payload: P,
    ids: { conversation_id?: string | null; run_id?: string | null; correlation_id?: string | null } = {},
  ): WidgetEvent<P> {
    const event: WidgetEvent<P> = {
      version: '1',
      type,
      instance_id: this.instanceId,
      timestamp: new Date().toISOString(),
      conversation_id: ids.conversation_id ?? null,
      run_id: ids.run_id ?? null,
      correlation_id: ids.correlation_id ?? null,
      payload,
    };
    for (const key of [type, '*']) {
      for (const listener of this.global.listeners.get(key) ?? []) {
        try {
          listener(event);
        } catch (error) {
          console.error('[WebAssistant] listener', error);
        }
      }
    }
    document.dispatchEvent(new CustomEvent(`webassistant:${type}`, { detail: event }));
    return event;
  }
}
