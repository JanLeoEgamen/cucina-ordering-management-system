/**
 * The "Without rice" controls of both menus (known-issues R1). The server rules are tested by
 * AlaCarteTest; this is the steppers, the totals shown, and what happens when the rice price
 * moves under an open page. The dish costs 100.00 and the rice 20.00 (support/setup.php).
 */
import { expect, fixtures, lastOrder, setRicePrice, signIn, test } from './support/test.js';

test.beforeEach(() => {
    setRicePrice(20);
});

test.describe('menu', () => {
    test('the no-rice count stays within the plates, the total follows, and the order saves it', async ({ page }) => {
        const { menu } = fixtures();

        await signIn(page, 'customer@example.com');
        await page.goto('/menu');

        const card = page.locator(`[data-dish-card="${menu.rice}"]`);
        await card.locator('[data-card-step="1"]').click();
        await card.locator('[data-card-step="1"]').click();
        await page.locator('#cart-fab').click();

        const line = page.locator(`#cart-lines [data-line-id="${menu.rice}"]`);
        const count = line.locator('[data-rice-count]');
        const less = line.locator('[data-rice-step="-1"]');
        const more = line.locator('[data-rice-step="1"]');
        const total = page.locator('#checkout-total');

        await expect(count).toHaveText('0');
        await expect(less).toBeDisabled();
        await expect(total).toHaveText('₱200.00');

        await more.click();
        await expect(total).toHaveText('₱180.00');
        await more.click();
        await expect(count).toHaveText('2');
        await expect(more).toBeDisabled();
        await expect(total).toHaveText('₱160.00');

        // One plate fewer takes the count down with it, and it does not come back by itself.
        await line.locator('[data-step="-1"]').click();
        await expect(count).toHaveText('1');
        await expect(total).toHaveText('₱80.00');
        await line.locator('[data-step="1"]').click();
        await expect(count).toHaveText('1');
        await expect(total).toHaveText('₱180.00');

        // A dish that does not come with rice has no such control.
        await page.keyboard.press('Escape');
        await page.goto('/menu');
        await page.locator(`[data-dish-card="${menu.plain}"] [data-card-step="1"]`).click();
        await page.locator('#cart-fab').click();
        await expect(page.locator(`#cart-lines [data-line-id="${menu.plain}"]`)).toBeVisible();
        await expect(page.locator(`#cart-lines [data-line-id="${menu.plain}"] [data-rice-row]`)).toHaveCount(0);
    });

    test('a rice price changed while the cart is open reaches it, and the order is saved at it', async ({ page }) => {
        const { menu } = fixtures();

        await signIn(page, 'customer@example.com');
        await page.goto('/menu');

        const card = page.locator(`[data-dish-card="${menu.rice}"]`);
        await card.locator('[data-card-step="1"]').click();
        await card.locator('[data-card-step="1"]').click();
        await page.locator('#cart-fab').click();

        const line = page.locator(`#cart-lines [data-line-id="${menu.rice}"]`);
        await line.locator('[data-rice-step="1"]').click();
        await expect(page.locator('#checkout-total')).toHaveText('₱180.00');

        setRicePrice(30);

        // The live poll brings it, with nothing clicked.
        await expect(page.locator('#checkout-total')).toHaveText('₱170.00');
        await expect(line.locator('[data-rice-count]')).toHaveText('1');

        await page.locator('#checkout-next').click();
        await page.locator('[data-pay-choice="cash"]').click();

        const confirm = page.locator('[data-step-panel="confirm"]');
        await expect(confirm).toBeVisible();

        const answer = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/orders');
        await page.locator('#checkout-next').click();
        expect((await answer).status()).toBe(200);
        await expect(page.locator('[data-step-panel="placed"]')).toBeVisible();

        expect(lastOrder('orders')).toEqual({
            total: 170,
            lines: [
                { quantity: 1, unitPrice: 100, noRice: false },
                { quantity: 1, unitPrice: 70, noRice: true },
            ],
        });
    });
});

test.describe('pre-order menu', () => {
    test('the no-rice count stays within the plates, typed or stepped', async ({ page }) => {
        const { preOrder } = fixtures();

        await signIn(page, 'customer@example.com');
        await page.goto('/pre-order-menu');

        const card = page.locator(`[data-dish-card="${preOrder.rice}"]`);
        const row = card.locator('[data-rice-row]');
        const input = card.locator('[data-rice-qty]');
        const less = card.locator('[data-rice-step="-1"]');
        const more = card.locator('[data-rice-step="1"]');
        const total = page.locator('#pre-order-total');

        // Hidden until the dish is in the order.
        await expect(row).toBeHidden();

        for (let plate = 0; plate < 3; plate++) {
            await card.locator('[data-step="1"]').click();
        }

        await expect(row).toBeVisible();
        await expect(less).toBeDisabled();
        await expect(total).toHaveText('₱300.00');

        for (let plate = 0; plate < 3; plate++) {
            await more.click();
        }

        await expect(input).toHaveValue('3');
        await expect(more).toBeDisabled();
        await expect(total).toHaveText('₱240.00');

        // Typed past the plates, then below zero.
        await input.fill('99');
        await input.blur();
        await expect(input).toHaveValue('3');
        await input.fill('-4');
        await input.blur();
        await expect(input).toHaveValue('0');
        await expect(total).toHaveText('₱300.00');

        // Fewer plates take the count down with them.
        await input.fill('3');
        await card.locator('[data-qty]').fill('1');
        await expect(input).toHaveValue('1');
        await expect(total).toHaveText('₱80.00');

        // No plates: the row goes, and the choice does not survive it.
        await card.locator('[data-step="-1"]').click();
        await expect(row).toBeHidden();
        await card.locator('[data-step="1"]').click();
        await expect(input).toHaveValue('0');

        await expect(page.locator(`[data-dish-card="${preOrder.plain}"] [data-rice-row]`)).toHaveCount(0);
    });

    test('an outdated rice price is refused, the page corrects itself, and the next try saves', async ({ page }) => {
        const { preOrder } = fixtures();

        await signIn(page, 'customer@example.com');
        await page.goto('/pre-order-menu');

        const card = page.locator(`[data-dish-card="${preOrder.rice}"]`);
        await card.locator('[data-step="1"]').click();
        await card.locator('[data-step="1"]').click();
        await card.locator('[data-rice-step="1"]').click();
        await expect(page.locator('#pre-order-total')).toHaveText('₱180.00');

        // This page has no live poll: it only finds out when the order is refused.
        setRicePrice(30);

        await page.locator('#pre-order-review').click();
        await page.locator('#pre-order-checkout-form [data-cash-tile]').click();

        const place = async () => {
            const answer = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/pre-orders');
            await page.locator('#pre-order-place').click();

            return (await answer).status();
        };

        expect(await place()).toBe(422);
        await expect(page.locator('#pre-order-errors')).toContainText('rice');
        await expect(page.locator('#pre-order-checkout-total')).toHaveText('₱170.00');
        await expect(page.locator('#pre-order-total')).toHaveText('₱170.00');
        await expect(card.locator('[data-rice-qty]')).toHaveValue('1');

        expect(await place()).toBe(200);
        await expect(page.locator('#pre-order-receipt')).toBeVisible();

        expect(lastOrder('pre_orders')).toEqual({
            total: 170,
            lines: [
                { quantity: 1, unitPrice: 100, noRice: false },
                { quantity: 1, unitPrice: 70, noRice: true },
            ],
        });
    });
});
