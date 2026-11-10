import { getErrorMessage } from '../utils/errors';
import { translate } from '../utils/i18n';

export type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'DELETE';

export interface RequestOptions<TBody = unknown> {
  body?: TBody;
  params?: Record<string, string | number | boolean | undefined | null>;
  headers?: Record<string, string>;
  signal?: AbortSignal;
  fetchImpl?: typeof fetch;
}

export interface ApiError extends Error {
  status: number;
  code?: string;
  details?: unknown;
}

interface BootstrapRestConfig {
  mwpBase?: string;
  nonce?: string;
}

const DEFAULT_HEADERS: Record<string, string> = {
  'Content-Type': 'application/json'
};

function getRestConfig(): BootstrapRestConfig {
  if (typeof window === 'undefined') {
    return {};
  }
  return window.MWP_BOOTSTRAP?.rest ?? {};
}

function buildUrl(path: string, params?: Record<string, string | number | boolean | undefined | null>): string {
  const { mwpBase } = getRestConfig();
  const base = mwpBase || '/wp-json/mwp/v1';
  const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
  const url = new URL(
    path.startsWith('http') ? path : `${base.replace(/\/$/, '')}/${path.replace(/^\//, '')}`,
    origin
  );
  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') {
        return;
      }
      url.searchParams.set(key, String(value));
    });
  }
  return url.toString();
}

async function parseJson(response: Response): Promise<unknown> {
  try {
    return await response.json();
  } catch (error) {
    return null;
  }
}

type ApiPayload = Record<string, unknown> & {
  success?: boolean;
  code?: string;
  message?: string;
  error_code?: string;
  error_message?: string;
};

function asPayload(payload: unknown): ApiPayload | null {
  if (typeof payload === 'object' && payload !== null) {
    return payload as ApiPayload;
  }
  return null;
}

async function request<TResponse, TBody = unknown>(method: HttpMethod, path: string, options: RequestOptions<TBody> = {}): Promise<TResponse> {
  const { nonce } = getRestConfig();
  const url = buildUrl(path, options.params);

  const headers: Record<string, string> = {
    ...DEFAULT_HEADERS,
    ...(options.headers || {})
  };

  if (nonce) {
    headers['X-WP-Nonce'] = nonce;
  }

  const fetchOptions: RequestInit = {
    method,
    headers,
    credentials: 'same-origin',
    signal: options.signal
  };

  if (options.body !== undefined) {
    const bodyIsJson = headers['Content-Type'] === 'application/json' && !(options.body instanceof FormData);
    fetchOptions.body = bodyIsJson ? (JSON.stringify(options.body) as BodyInit) : (options.body as unknown as BodyInit);
  }

  const fetchImpl = options.fetchImpl ?? fetch;
  const response = await fetchImpl(url, fetchOptions);
  const payload = await parseJson(response);
  const payloadRecord = asPayload(payload);
  const explicitFailure = payloadRecord?.success === false;

  if (!response.ok || explicitFailure) {
    const codeValue = (payloadRecord?.code ?? payloadRecord?.error_code);
    const code = typeof codeValue === 'string' ? codeValue : undefined;
    const messageCandidate = payloadRecord?.message ?? payloadRecord?.error_message;
    const resolvedMessage = typeof messageCandidate === 'string' ? messageCandidate : `HTTP ${response.status}`;
    const message = getErrorMessage(code, resolvedMessage);
    const error: ApiError = Object.assign(new Error(message), {
      status: response.status,
      code,
      details: payload
    });
    throw error;
  }

  if (payload !== null) {
    return payload as TResponse;
  }

  // No JSON payload; assume empty success.
  return { success: true } as unknown as TResponse;
}

export const apiClient = {
  get: <TResponse>(path: string, options?: Omit<RequestOptions, 'body'>) => request<TResponse>('GET', path, options),
  post: <TResponse, TBody = unknown>(path: string, options?: RequestOptions<TBody>) => request<TResponse, TBody>('POST', path, options),
  patch: <TResponse, TBody = unknown>(path: string, options?: RequestOptions<TBody>) => request<TResponse, TBody>('PATCH', path, options),
  delete: <TResponse>(path: string, options?: Omit<RequestOptions, 'body'>) => request<TResponse>('DELETE', path, options)
};

export function formatApiError(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }
  return translate('An unexpected error occurred.', 'An unexpected error occurred.');
}

export default apiClient;
