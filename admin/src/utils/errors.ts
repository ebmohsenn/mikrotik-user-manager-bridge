import { translate } from './i18n';

const ERROR_MESSAGES: Record<string, string> = {
  locked: translate('Settings are locked while connected. Disconnect first to edit.', 'Settings are locked while connected. Disconnect first to edit.'),
  circuit_open: translate('The MikroTik router is temporarily unavailable. Please try again shortly.', 'The MikroTik router is temporarily unavailable. Please try again shortly.'),
  not_found: translate('The requested resource could not be found.', 'The requested resource could not be found.'),
  api_error: translate('The MikroTik API returned an error.', 'The MikroTik API returned an error.'),
  rest_error: translate('The MikroTik REST API is currently unavailable.', 'The MikroTik REST API is currently unavailable.'),
  rate_limited: translate('Too many requests. Please slow down.', 'Too many requests. Please slow down.'),
};

export function getErrorMessage(code?: string, fallback?: string): string {
  if (code && ERROR_MESSAGES[code]) {
    return ERROR_MESSAGES[code];
  }
  if (fallback) {
    return fallback;
  }
  return translate('An unexpected error occurred.', 'An unexpected error occurred.');
}
