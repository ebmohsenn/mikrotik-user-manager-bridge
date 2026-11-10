import { getErrorMessage } from '../utils/errors';

describe('error utils', () => {
  it('returns mapped message when code is known', () => {
    const message = getErrorMessage('locked');
    expect(message).toMatch(/Settings are locked/i);
  });

  it('falls back to provided message when code unknown', () => {
    const message = getErrorMessage('custom', 'Custom message');
    expect(message).toBe('Custom message');
  });

  it('falls back to default translation when no message provided', () => {
    const message = getErrorMessage('unknown');
    expect(message).toMatch(/unexpected error/i);
  });
});
