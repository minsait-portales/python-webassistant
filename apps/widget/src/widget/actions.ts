/**
 * Ejecución de acciones (CG-10.6): confirmación, bloqueo de dobles envíos,
 * idempotencia y delegación de host actions a la página anfitriona con correlación.
 */
import type { ActionDescriptor, HostAction, WebAssistantClient } from '@webassistant/sdk';
import { ApiError } from '@webassistant/sdk';

import type { HostActionResult, WebAssistantGlobal } from '../shared';
import { type Announcer, confirmDialog } from './a11y';
import type { EventBus } from './events';
import type { Translate } from './i18n';

const HOST_ACTION_TIMEOUT_MS = 30_000;

export interface ActionContext {
  conversationId: string;
  runId: string | null;
}

export interface ActionOutcome {
  status: 'succeeded' | 'failed' | 'cancelled' | 'ignored';
  data?: Record<string, unknown>;
  message?: string;
}

function idempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

function describeParams(params: Record<string, unknown>, t: Translate): Array<[string, string]> {
  return Object.entries(params)
    .filter(([, value]) => ['string', 'number', 'boolean'].includes(typeof value) || Array.isArray(value))
    .slice(0, 6)
    .map(([key, value]) => [
      key.replace(/_/g, ' '),
      Array.isArray(value) ? t('itemsCount', { count: value.length }) : String(value).slice(0, 120),
    ]);
}

export class ActionController {
  private readonly inflight = new Set<string>();

  constructor(
    private readonly client: WebAssistantClient,
    private readonly global: WebAssistantGlobal,
    private readonly events: EventBus,
    private readonly announcer: Announcer,
    private readonly dialogHost: HTMLElement,
    private readonly t: Translate,
  ) {}

  async run(
    action: ActionDescriptor,
    params: Record<string, unknown>,
    context: ActionContext,
  ): Promise<ActionOutcome> {
    const key = `${action.skill}:${action.name}:${JSON.stringify(params)}`;
    if (this.inflight.has(key)) return { status: 'ignored' };
    this.inflight.add(key);
    try {
      this.events.emit(
        'action_requested',
        { skill: action.skill, action: action.name, params },
        { conversation_id: context.conversationId, run_id: context.runId },
      );
      const proposal = await this.client.prepareAction({
        conversation_id: context.conversationId,
        run_id: context.runId,
        skill: action.skill,
        action: action.name,
        params,
      });
      if (proposal.requires_confirmation) {
        const confirmed = await confirmDialog(this.dialogHost, this.t, {
          title: proposal.label || action.label || this.t('confirmTitle'),
          description: proposal.description || action.description,
          details: describeParams(params, this.t),
          confirmLabel: this.t('confirm'),
        });
        if (!confirmed) return { status: 'cancelled' };
      }
      const executed = await this.client.executeAction(
        proposal.execution_id,
        idempotencyKey(),
        proposal.confirmation_token,
      );
      let outcome: ActionOutcome;
      if (executed.type === 'host' && executed.host_action) {
        outcome = await this.delegate(executed.host_action, context);
      } else {
        const status = executed.status === 'succeeded' ? 'succeeded' : 'failed';
        outcome = { status, data: (executed.result?.data as Record<string, unknown>) ?? {} };
      }
      this.events.emit(
        'action_result',
        { skill: action.skill, action: action.name, status: outcome.status, data: outcome.data ?? {} },
        {
          conversation_id: context.conversationId,
          run_id: context.runId,
          correlation_id: executed.execution_id,
        },
      );
      if (!action.auto) {
        this.announcer.say(
          outcome.status === 'succeeded'
            ? outcome.message || this.t('actionDone')
            : outcome.message || this.t('actionFailed'),
          outcome.status !== 'succeeded',
        );
      }
      return outcome;
    } catch (error) {
      const message = error instanceof ApiError ? error.userMessage : this.t('actionFailed');
      this.announcer.say(message, true);
      return { status: 'failed', message };
    } finally {
      this.inflight.delete(key);
    }
  }

  private async delegate(hostAction: HostAction, context: ActionContext): Promise<ActionOutcome> {
    const handler = this.global.hostActions.get(hostAction.name);
    this.events.emit(
      'host_action',
      { ...hostAction },
      {
        conversation_id: context.conversationId,
        run_id: context.runId,
        correlation_id: hostAction.correlation_id,
      },
    );
    if (!handler) {
      await this.client
        .reportAction(hostAction.execution_id, 'failed', { reason: 'no_handler' })
        .catch(() => undefined);
      return { status: 'failed', message: this.t('actionUnavailable') };
    }
    let result: HostActionResult | void;
    try {
      result = await Promise.race([
        Promise.resolve(handler(hostAction)),
        new Promise<never>((_, reject) =>
          window.setTimeout(() => reject(new Error('timeout')), HOST_ACTION_TIMEOUT_MS),
        ),
      ]);
    } catch {
      await this.client
        .reportAction(hostAction.execution_id, 'failed', { reason: 'handler_error' })
        .catch(() => undefined);
      return { status: 'failed' };
    }
    const status = result?.status === 'failed' ? 'failed' : 'succeeded';
    const data = result?.data ?? {};
    await this.client.reportAction(hostAction.execution_id, status, data).catch(() => undefined);
    return { status, data, message: result?.message };
  }
}
