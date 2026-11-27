import React from 'react';
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import RouterHealth from '../components/RouterHealth.jsx';
import { ToastContext } from '../toast.jsx';
import { RefreshProvider, useRefreshControl } from '../common/RefreshContext.jsx';

// The refresh button lives in the app header and reaches components through RefreshContext.
const RefreshButton = () => {
  const { trigger } = useRefreshControl();
  return (
    <button type="button" onClick={() => trigger()}>
      Refresh
    </button>
  );
};

type TestGlobal = typeof globalThis & {
  MWP_BOOTSTRAP?: {
    rest?: {
      mwpBase: string;
      nonce: string;
    };
  };
};

const createResponse = (body: unknown, init?: ResponseInit) =>
  new Response(JSON.stringify(body), {
    headers: { 'Content-Type': 'application/json' },
    ...init
  });

describe('RouterHealth', () => {
  const globalWithBootstrap = global as TestGlobal;
  const originalFetch = global.fetch;
  const originalBootstrap = globalWithBootstrap.MWP_BOOTSTRAP;
  beforeEach(() => {
    globalWithBootstrap.MWP_BOOTSTRAP = {
      rest: { mwpBase: '/wp-json/mwp/v1', nonce: '123' }
    };
  });

  afterEach(() => {
    global.fetch = originalFetch;
    globalWithBootstrap.MWP_BOOTSTRAP = originalBootstrap;
  });

  it('renders health data from API', async () => {
    const payload = {
      success: true,
      data: {
        configured: true,
        master_mode: false,
        capabilities: { dual_namespace: true },
        binary: { reachable: true },
        rest: { configured: true, reachable: true, user_profile: true }
      }
    };
    const fetchMock: jest.MockedFunction<typeof fetch> = jest
      .fn()
      .mockResolvedValue(createResponse(payload, { status: 200 }));
    global.fetch = fetchMock;

    await act(async () => {
      render(
        <ToastContext.Provider value={() => undefined}>
          <RouterHealth />
        </ToastContext.Provider>
      );
    });

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const configured = await screen.findAllByText(/Configured:/i);
    expect(configured.length).toBeGreaterThan(0);
  });

  it('shows error when request fails', async () => {
    const fetchMock: jest.MockedFunction<typeof fetch> = jest
      .fn()
      .mockResolvedValue(createResponse({ success: false, message: 'fail' }, { status: 500 }));
    global.fetch = fetchMock;

    await act(async () => {
      render(
        <ToastContext.Provider value={() => undefined}>
          <RefreshProvider>
            <RefreshButton />
            <RouterHealth />
          </RefreshProvider>
        </ToastContext.Provider>
      );
    });

    await screen.findByText(/fail/);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /Refresh/i }));
    });
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
  });
});
