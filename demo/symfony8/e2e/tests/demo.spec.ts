import { test, expect, type Page } from '@playwright/test';

/**
 * REQ-DEMO-013 — happy path of the Veri*Factu demo (Doctrine hash chain): the page renders inside the demo platform
 * shell, issuing an invoice shows the generated record, and an invalid invoice shows its errors as a flash.
 */
async function fillInvoice(page: Page, serie: string, fecha = '09-07-2026'): Promise<void> {
  await page.getByLabel('Serie + número').fill(serie);
  await page.getByLabel('Fecha expedición (dd-mm-yyyy)').fill(fecha);
  await page.getByLabel('Cuota total').fill('21.00');
  await page.getByLabel('Importe total').fill('121.00');
}

test.describe('Verifactu demo', () => {
  test('the page renders inside the demo platform shell', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByTestId('demo-platform')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'VerifactuBundle Demo', level: 1 })).toBeVisible();
    await expect(page.getByTestId('demo-system-state')).toContainText('Hash chain (Doctrine)');
    await expect(page.getByTestId('demo-invoice-form')).toBeVisible();
  });

  test('issuing an invoice appends a record to the hash chain', async ({ page }) => {
    await page.goto('/');
    await fillInvoice(page, `FAC-E2E-${Date.now()}`);
    await page.getByRole('button', { name: 'Emitir factura Veri*Factu' }).click();
    const result = page.getByTestId('demo-result');
    await expect(result).toBeVisible();
    await expect(result).toContainText('Hash:');
    await expect(page.getByTestId('demo-result-xml')).toBeVisible();
    await expect(page.locator('.nowo-ui-flash--error')).toHaveCount(0);
  });

  test('an invalid invoice is reported in a flash under the page header', async ({ page }) => {
    await page.goto('/');
    await fillInvoice(page, `FAC-E2E-BAD-${Date.now()}`, '99-99-9999');
    await page.getByRole('button', { name: 'Emitir factura Veri*Factu' }).click();
    await expect(page.locator('.nowo-ui-flash--error').first()).toBeVisible();
    await expect(page.getByTestId('demo-result')).toHaveCount(0);
  });
});
