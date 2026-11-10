import '@testing-library/jest-dom';
import fetch, { Headers, Request, Response } from 'cross-fetch';

const globalAny = global as typeof globalThis & {
  fetch?: typeof fetch;
  Headers?: typeof Headers;
  Request?: typeof Request;
  Response?: typeof Response;
};

if (!globalAny.fetch) {
  globalAny.fetch = fetch as unknown as typeof global.fetch;
}

globalAny.Headers = Headers;
globalAny.Request = Request;
globalAny.Response = Response;

beforeEach(() => {
  globalAny.fetch = jest.fn(() =>
    Promise.resolve({
      ok: true,
      status: 200,
      statusText: 'OK',
      json: async () => ({ success: true, data: [] })
    })
  ) as unknown as typeof fetch;
});

afterEach(() => {
  if (typeof globalAny.fetch === 'function' && 'mockReset' in globalAny.fetch) {
    (globalAny.fetch as jest.Mock).mockReset();
  }
});
