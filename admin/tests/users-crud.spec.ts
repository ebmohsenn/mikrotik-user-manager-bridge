import { test, expect } from '@playwright/test';

const adminUser = process.env.PLAYWRIGHT_ADMIN_USER;
const adminPass = process.env.PLAYWRIGHT_ADMIN_PASS;

const shouldRun = !!(process.env.PLAYWRIGHT_BASE_URL && adminUser && adminPass);

test.describe('Users CRUD flows', () => {
  test.skip(!shouldRun, 'PLAYWRIGHT_BASE_URL/credentials not configured');

  test.beforeEach(async ({ page }) => {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', adminUser!);
    await page.fill('#user_pass', adminPass!);
    await page.click('#wp-submit');
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await page.goto('/wp-admin/admin.php?page=mwp_manager');
    await expect(page.locator('#mwp-admin-root')).toBeVisible();
  });

  test('opens new user form', async ({ page }) => {
    const newUserButton = page.getByRole('button', { name: /New User/i });
    await newUserButton.click();
    await expect(page.getByPlaceholder('Username')).toBeVisible();
    await page.getByRole('button', { name: /Cancel/i }).click();
    await expect(page.getByPlaceholder('Username')).toBeHidden();
  });

  test('searches for a user', async ({ page }) => {
    const searchBox = page.getByPlaceholder(/Search users/i);
    await searchBox.fill('alice');
    await page.waitForTimeout(500);
    await expect(searchBox).toHaveValue('alice');
  });

  test('opens edit row controls', async ({ page }) => {
    const editButton = page.getByRole('button', { name: /^Edit$/ }).first();
    await editButton.click();
    await expect(page.getByRole('button', { name: /^Save$/ })).toBeVisible();
    await page.getByRole('button', { name: /^Cancel$/ }).click();
  });

  test('cancel delete confirmation', async ({ page }) => {
    const deleteButton = page.getByRole('button', { name: /^Delete$/ }).first();
    page.once('dialog', dialog => dialog.dismiss());
    await deleteButton.click();
  });
});
