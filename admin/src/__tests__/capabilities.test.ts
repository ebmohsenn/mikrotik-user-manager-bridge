import { fetchCapabilities } from '../api/capabilities';

type TestGlobal = typeof globalThis & {
  MWP_BOOTSTRAP?: {
    rest?: {
      mwpBase: string;
      nonce: string;
    };
  };
};

describe('fetchCapabilities', () => {
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
        nonce: 'abc123'
      }
    };
  });

  afterEach(() => {
    globalWithBootstrap.MWP_BOOTSTRAP = originalBootstrap;
  });

  it('returns data when API succeeds', async () => {
    const fakeFetch: jest.MockedFunction<typeof fetch> = jest.fn().mockResolvedValue(
      createResponse({ success: true, data: { rest: { available: true } } }, { status: 200 })
    );

    const result = await fetchCapabilities(fakeFetch);
    expect(fakeFetch).toHaveBeenCalledWith('/wp-json/mwp/v1/router/capabilities', expect.objectContaining({
      headers: expect.objectContaining({ 'X-WP-Nonce': 'abc123' })
    }));
    expect(result).toEqual({ rest: { available: true } });
  });

  it('throws when API fails', async () => {
    const fakeFetch: jest.MockedFunction<typeof fetch> = jest.fn().mockResolvedValue(
      createResponse({ success: false, message: 'Boom' }, { status: 500 })
    );

    await expect(fetchCapabilities(fakeFetch)).rejects.toThrow('Boom');
  });
});
