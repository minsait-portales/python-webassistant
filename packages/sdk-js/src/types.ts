/**
 * Tipos de los contratos v1 (packages/contracts). Los tipos de petición/respuesta
 * de la API se generan desde OpenAPI en `openapi.d.ts`; aquí se añaden los
 * contratos de presentación, eventos y host actions que no forman parte de OpenAPI.
 */
import type { components } from './openapi';

export type Schemas = components['schemas'];
export type BootstrapResponse = Schemas['BootstrapResponse'];
export type SessionResponse = Schemas['SessionResponse'];
export type TokenResponse = Schemas['TokenResponse'];
export type TokenRequest = Schemas['TokenRequest'];
export type SearchBody = Schemas['SearchBody'];

export interface Problem {
  type: string;
  title: string;
  status: number;
  code: string;
  detail?: string;
  retryable?: boolean;
  user_message?: string;
  correlation_id?: string;
  errors?: Array<{ path: string; message: string }>;
}

export interface Step {
  title: string;
  body?: string;
}

export interface ProductItem {
  product_id: string;
  variation_id?: string | null;
  title: string;
  body?: string;
  url?: string;
  image_url?: string;
  image_alt?: string;
  price?: string;
  price_amount?: number | null;
  currency?: string;
  quantity?: number;
  category?: string;
  brand?: string;
  store_name?: string;
  availability?: string;
  rating?: number | null;
  badges?: string[];
  actions?: string[];
}

export interface SourceLink {
  title: string;
  url?: string;
  snippet?: string;
  source_id?: string;
}

export interface Presentation<T = Record<string, unknown>> {
  type: string;
  version: string;
  data: T;
}

export type ActionType = 'internal' | 'host';

export interface ActionDescriptor {
  name: string;
  skill: string;
  type: ActionType;
  label: string;
  description?: string;
  confirmation: 'required' | 'none';
  effects?: boolean;
  auto?: boolean;
  target?: 'presentation' | 'item';
  params?: Record<string, unknown>;
}

export interface Citation {
  source_id: string;
  title: string;
  url?: string | null;
  snippet?: string;
  locale?: string;
  document_type?: string;
  knowledge_base?: string;
  score?: number;
}

export interface Usage {
  provider_calls?: number;
  input_tokens?: number;
  cached_tokens?: number;
  output_tokens?: number;
  reasoning_tokens?: number;
  total_tokens?: number;
  total_cost?: string;
  currency?: string;
}

export interface ChatResponse {
  api_version: '1';
  conversation_id: string;
  run_id: string;
  message_id?: string;
  skill: string | null;
  reply: string;
  presentation: Presentation | null;
  keep_presentation?: boolean;
  actions: ActionDescriptor[];
  citations: Citation[];
  responsive_button_label?: string;
  usage?: Usage;
}

export interface ConversationView {
  conversation_id: string;
  assistant_id: string;
  status: 'active' | 'archived' | 'deleted';
  locale: string;
  title: string;
  created_at: string;
  last_message_at: string | null;
}

export interface HistoryMessage {
  message_id: string;
  role: 'user' | 'assistant';
  content: string;
  run_id: string | null;
  created_at: string;
  presentation?: Presentation | null;
  actions?: ActionDescriptor[];
  citations?: Citation[];
  keep_presentation?: boolean;
  skill?: string | null;
}

export interface ConversationHistory extends ConversationView {
  messages: HistoryMessage[];
}

export type StreamEventType =
  | 'run.started'
  | 'run.status'
  | 'reply.delta'
  | 'tool.started'
  | 'tool.finished'
  | 'run.completed'
  | 'run.failed'
  | 'run.cancelled'
  | 'heartbeat';

export interface StreamEvent {
  type: StreamEventType;
  run_id: string;
  seq: number;
  conversation_id?: string;
  status?: string;
  delta?: string;
  skill?: string;
  response?: ChatResponse;
  error?: Problem;
}

export interface HostAction {
  version: '1';
  name: string;
  execution_id: string;
  correlation_id: string;
  skill?: string;
  action?: string;
  run_id?: string | null;
  params: Record<string, unknown>;
  expires_at?: string | null;
}

export interface ActionExecution {
  execution_id: string;
  skill: string;
  action: string;
  type: ActionType;
  status: 'proposed' | 'delegated' | 'succeeded' | 'failed' | 'expired';
  requires_confirmation: boolean;
  params: Record<string, unknown>;
  run_id: string | null;
  result: Record<string, unknown> | null;
  expires_at: string | null;
  label?: string;
  description?: string;
  effects?: boolean;
  confirmation_token?: string;
  confirmation_expires_at?: string;
  host_action?: HostAction | null;
  replayed?: boolean;
}

export type WidgetEventType =
  | 'ready'
  | 'open'
  | 'close'
  | 'message_sent'
  | 'response'
  | 'presentation'
  | 'action_requested'
  | 'host_action'
  | 'action_result'
  | 'error'
  | 'reset';

export interface WidgetEvent<P = Record<string, unknown>> {
  version: '1';
  type: WidgetEventType;
  instance_id: string;
  timestamp: string;
  conversation_id: string | null;
  run_id: string | null;
  correlation_id: string | null;
  payload: P;
}
