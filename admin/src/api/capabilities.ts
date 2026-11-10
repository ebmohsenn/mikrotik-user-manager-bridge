export interface CapabilityMatrix {
  binary?: Record<string, unknown>;
  rest?: Record<string, unknown>;
  profile_updates?: Record<string, unknown>;
  quirks?: Record<string, unknown>;
  [key: string]: unknown;
}

interface CapabilityPayload {
  success?: boolean;
  data?: CapabilityMatrix;
  message?: string;
}

function resolveBootstrapRest() {
  if (typeof window === 'undefined') {
    return { base: '/wp-json/mwp/v1', nonce: '' };
  }
  const rest = window.MWP_BOOTSTRAP?.rest;
  return {
    base: rest?.mwpBase ?? '/wp-json/mwp/v1',
    nonce: rest?.nonce ?? ''
  };
}

function isCapabilityPayload(value: unknown): value is CapabilityPayload {
  return typeof value === 'object' && value !== null;
}

export async function fetchCapabilities(fetchImpl: typeof fetch = fetch): Promise<CapabilityMatrix> {
  const { base, nonce } = resolveBootstrapRest();

  const response = await fetchImpl(`${base}/router/capabilities`, {
    headers: {
      'X-WP-Nonce': nonce,
      'Content-Type': 'application/json'
    },
    credentials: 'same-origin'
  });

  const payload: unknown = await response.json();
  if (!response.ok) {
    const fallback = `HTTP ${response.status}`;
    const message = isCapabilityPayload(payload) && typeof payload.message === 'string' ? payload.message : fallback;
    throw new Error(message);
  }
  if (!isCapabilityPayload(payload) || payload.success === false) {
    const message = (isCapabilityPayload(payload) && typeof payload.message === 'string') ? payload.message : `HTTP ${response.status}`;
    throw new Error(message);
  }

  return payload.data ?? {};
}
