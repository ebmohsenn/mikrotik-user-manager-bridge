import { test, expect } from '@playwright/test';

test.describe('Admin UI smoke', () => {
  test('dashboard loads root element', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=mwp_manager');
    await expect(page.locator('#mwp-admin-root')).toBeVisible();
  });
});
