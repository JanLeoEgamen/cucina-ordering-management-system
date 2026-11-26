/**
 * The Roles and Users pages as someone who manages both but is not a Super Admin. The server
 * rules are tested by PrivilegeRulesTest; this is what the pages let that person click.
 */
import { expect, fixtures, sidebar, signIn, test } from './support/test.js';

const row = (page, table, text) => page.locator(`${table} tbody tr`, { hasText: text });

test('permissions you do not have stay unticked, also after Select all and View only', async ({ page }) => {
    const { permissions } = fixtures();

    await signIn(page, 'manager@example.com');
    await page.goto('/roles');
    await page.locator('#create-role').click();

    const form = page.locator('#create-role-form');
    const box = (name) => form.locator(`input[name="permissions[]"][value="${permissions[name]}"]`);
    const picker = form.locator('[data-checkbox-picker]');

    await expect(box('verify payments')).toBeDisabled();
    await expect(box('view payments')).toBeDisabled();
    await expect(box('view users')).toBeEnabled();

    await picker.getByRole('button', { name: 'Select all' }).first().click();
    await expect(box('view users')).toBeChecked();
    await expect(box('edit roles')).toBeChecked();
    // The ordering permissions count as held by everyone.
    await expect(box('create orders')).toBeChecked();
    await expect(box('verify payments')).not.toBeChecked();
    await expect(form.locator('input[name="permissions[]"]:disabled:checked')).toHaveCount(0);

    await picker.getByRole('button', { name: 'View only' }).click();
    await expect(box('view roles')).toBeChecked();
    await expect(box('edit roles')).not.toBeChecked();
    await expect(box('view payments')).not.toBeChecked();

    // What the buttons left ticked is accepted by the server.
    await page.fill('#create-role-name', 'Made in the browser');
    await page.locator('#create-role-submit').click();
    await page.locator('#save-modal-button').click();
    await expect(row(page, '#roles-table', 'Made in the browser')).toBeVisible();
});

test('Customer keeps its name, and stronger roles offer no Edit', async ({ page }) => {
    await signIn(page, 'manager@example.com');
    await page.goto('/roles');

    await expect(row(page, '#roles-table', 'Cashier').locator('[data-row-action="view"]')).toBeVisible();
    await expect(row(page, '#roles-table', 'Cashier').locator('[data-row-action="edit"]')).toHaveCount(0);
    await expect(row(page, '#roles-table', 'Super Admin').locator('[data-row-action="edit"]')).toHaveCount(0);
    await expect(row(page, '#roles-table', 'Customer').locator('[data-row-action="delete"]')).toHaveCount(0);

    await row(page, '#roles-table', 'Customer').locator('[data-row-action="edit"]').click();
    await expect(page.locator('#edit-role-name')).toHaveValue('Customer');
    await expect(page.locator('#edit-role-name')).not.toBeEditable();
    await expect(page.locator('#edit-role-name-locked')).toBeVisible();
});

test('the Users page offers no way into a stronger account, also after a soft visit', async ({ page }) => {
    const { roles } = fixtures();

    await signIn(page, 'manager@example.com');
    await page.goto('/roles');
    await sidebar(page, '/users');

    const admin = row(page, '#users-table', 'test@example.com');
    await expect(admin.locator('[data-row-action="view"]')).toBeVisible();
    await expect(admin.locator('[data-row-action="edit"]')).toHaveCount(0);
    await expect(admin.locator('[data-row-action="delete"]')).toHaveCount(0);
    await expect(row(page, '#users-table', 'customer@example.com').locator('[data-row-action="edit"]')).toBeVisible();

    await page.locator('#create-user').click();
    await page.locator('#create-user-roles').click();

    const offered = (name) => page.locator(`#create-user-form input[name="roles[]"][value="${roles[name]}"]`);
    await expect(offered('Customer')).toBeVisible();
    await expect(offered('User manager')).toBeVisible();
    await expect(offered('Super Admin')).toHaveCount(0);
    await expect(offered('Cashier')).toHaveCount(0);
});
