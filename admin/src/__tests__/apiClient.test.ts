import { apiClient } from '../api/apiClient';

type TestGlobal = typeof globalThis & {
  MWP_BOOTSTRAP?: {
    rest?: {
      mwpBase: string;
      nonce: string;
    };
  };
};

describe('apiClient', () => {
  const globalWithBootstrap = global as TestGlobal;
  const originalBootstrap = globalWithBootstrap.MWP_BOOTSTRAP;

  const createResponse = (body: unknown, init?: ResponseInit) =>
    new Response(JSON.stringify(body), {
      headers: { 'Content-Type': 'application/json' },
      ...init
    });

  beforeEach(() => {
    globalWithBootstrap.MWP_BOOTSTRAP = {
      rest: {
        mwpBase: '/wp-json/mwp/v1',
        nonce: 'nonce123'
      }
    };
  });

  afterEach(() => {
    globalWithBootstrap.MWP_BOOTSTRAP = originalBootstrap;
  });

  it('performs GET and returns payload data', async () => {
    const fakeFetch: jest.MockedFunction<typeof fetch> = jest.fn().mockResolvedValue(
      createResponse({ success: true, data: { value: 42 } }, { status: 200 })
    );

    const result = await apiClient.get<{ success: boolean; data: { value: number } }>('status', {
      fetchImpl: fakeFetch
    });

    expect(fakeFetch).toHaveBeenCalledTimes(1);
    expect(fakeFetch.mock.calls[0][0]).toContain('/wp-json/mwp/v1/status');
    expect(fakeFetch.mock.calls[0][1]).toEqual(expect.objectContaining({ credentials: 'same-origin' }));
    expect(result.data.value).toBe(42);
  });

  it('throws when API returns error payload', async () => {
    const fakeFetch: jest.MockedFunction<typeof fetch> = jest.fn().mockResolvedValue(
      createResponse({ success: false, code: 'locked', message: 'Locked' }, { status: 200 })
    );

    await expect(apiClient.get('status', { fetchImpl: fakeFetch })).rejects.toThrow(/Settings are locked/i);
  });
});
