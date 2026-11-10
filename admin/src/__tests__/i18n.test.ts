import { translate } from '../utils/i18n';

type WindowWithI18n = typeof window & {
  wp?: {
    i18n?: {
      __?: (text: string, domain?: string) => string;
    };
  };
};

describe('i18n utils', () => {
  const windowWithI18n = window as WindowWithI18n;
  const original = windowWithI18n.wp;

  afterEach(() => {
    windowWithI18n.wp = original;
  });

  it('uses wp.i18n when available', () => {
    windowWithI18n.wp = {
      i18n: {
        __: (text: string) => `translated:${text}`
      }
    };
    expect(translate('Hello', 'fallback')).toBe('translated:Hello');
  });

  it('falls back to provided string when wp.i18n missing', () => {
    windowWithI18n.wp = undefined;
    expect(translate('Hello', 'fallback')).toBe('fallback');
  });
});
