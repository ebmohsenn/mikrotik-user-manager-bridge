const DEFAULT_DOMAIN = 'mikrotik-user-manager-bridge';

type TranslateFn = (text: string, domain?: string) => string;

declare global {
  interface Window {
    wp?: {
      i18n?: {
        __?: TranslateFn;
      };
    };
  }
}

export function translate(text: string, fallback: string, domain: string = DEFAULT_DOMAIN): string {
  const fn = window?.wp?.i18n?.__;
  if (typeof fn === 'function') {
    return fn(text, domain);
  }
  return fallback;
}

export function sprintf(template: string, ...args: Array<string | number>): string {
  let index = 0;
  return template.replace(/%s|%d/g, () => String(args[index++] ?? ''));
}
