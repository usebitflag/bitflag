import { describe, expect, it } from 'vitest';
import { urlForEnvironment } from './environment';

describe('urlForEnvironment', () => {
  it('swaps only the environment segment and keeps the page', () => {
    expect(urlForEnvironment('/acme/storefront/production/overview', 'staging')).toBe(
      '/acme/storefront/staging/overview',
    );
    expect(urlForEnvironment('/acme/storefront/production/flags/checkout-v2', 'dev')).toBe(
      '/acme/storefront/dev/flags/checkout-v2',
    );
  });

  it('leaves paths without an environment segment alone', () => {
    expect(urlForEnvironment('/acme/storefront', 'staging')).toBe('/acme/storefront');
  });
});
