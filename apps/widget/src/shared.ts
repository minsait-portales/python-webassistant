/** Tipos compartidos por loader y widget (contrato `window.WebAssistant` v1). */
import type { HostAction, WidgetEvent, WidgetEventType } from '@webassistant/sdk';

export type TokenInput = string | (() => Promise<string>);

export interface LoaderOptions {
  siteId: string;
  assistantId?: string;
  apiBase: string;
  locale?: string;
  resultTarget?: string;
  open?: boolean;
  token?: string;
  position?: 'left' | 'right';
}

export interface MountOptions extends LoaderOptions {
  instanceId: string;
}

/** Resultado que devuelve el handler de una host action registrado por el portal. */
export interface HostActionResult {
  status?: 'succeeded' | 'failed';
  data?: Record<string, unknown>;
  message?: string;
}

export type HostActionHandler = (
  action: HostAction,
) => Promise<HostActionResult | void> | HostActionResult | void;

export interface PublicApi {
  setToken(token: TokenInput | null): void;
  open(): void;
  close(): void;
  send(text: string): Promise<void>;
}

export type QueuedCall = [string, ...unknown[]];

export interface WebAssistantGlobal {
  __loader: string;
  instances: Map<string, PublicApi>;
  listeners: Map<string, Set<(event: unknown) => void>>;
  hostActions: Map<string, HostActionHandler>;
  pending: QueuedCall[];
  on<T = Record<string, unknown>>(
    type: WidgetEventType | '*',
    handler: (event: WidgetEvent<T>) => void,
  ): () => void;
  registerHostAction(name: string, handler: HostActionHandler): void;
  setToken(token: TokenInput | null, instanceId?: string): void;
  open(instanceId?: string): void;
  close(instanceId?: string): void;
  send(text: string, instanceId?: string): void;
}
