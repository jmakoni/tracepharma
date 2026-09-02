import { chromium } from 'playwright';

const BASE = 'https://demo2.internal.vatengi.com';
const EMAIL = 'owner@demo.test';
const PASSWORD = 'password';
const SSCC = '003011610012238857';
const CUSTOMER_SEARCH = 'Joel Test Dispenser';
const CONNECTION_LABEL = 'Joel EPCIS Email Test';
const ASN = 'ASN-JOEL-TEST-001';
const PO = 'PO-JOEL-TEST-001';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function waitForLivewire(page) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await sleep(800);
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  try {
    console.log('1. Login');
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('textbox', { name: /email/i }).fill(EMAIL);
    await page.getByRole('textbox', { name: /password/i }).fill(PASSWORD);
    await page.getByRole('button', { name: /sign in|log in/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 });
    await waitForLivewire(page);
    console.log('   Logged in:', page.url());

    console.log('2. Open Scan Out');
    await page.goto(`${BASE}/scan-out`, { waitUntil: 'domcontentloaded' });
    await waitForLivewire(page);

    console.log('3. New ship order → Debug Ship Site');
    await page.getByRole('button', { name: 'New ship order' }).click();
    await waitForLivewire(page);
    await page.getByRole('button', { name: /Debug Ship Site/i }).click();
    await waitForLivewire(page);

    const sessionMatch = page.url().match(/session=(\d+)/);
    const sessionId = sessionMatch?.[1] ?? 'unknown';
    console.log('   Session:', sessionId);

    console.log('4. Scan SSCC');
    const scanInput = page.locator('#scan-out-input');
    await scanInput.fill(SSCC);
    await page.getByRole('button', { name: 'ADD' }).click();
    await waitForLivewire(page);
    await page.locator('.stat-value').filter({ hasText: /^1$/ }).first().waitFor({ timeout: 15000 });
    const confirmed = await page.locator('.stat-value').first().textContent();
    console.log('   Confirmed count:', confirmed?.trim());

    console.log('5. Customer step');
    await page.getByRole('button', { name: 'Next: Customer →' }).click();
    await waitForLivewire(page);
    await page.locator('#customer-search').fill(CUSTOMER_SEARCH);
    await sleep(1500);
    await page.locator('[role="listbox"] button', { hasText: CUSTOMER_SEARCH }).first().click();
    await waitForLivewire(page);
    await page.locator('#outbound-connection').selectOption({ label: CONNECTION_LABEL });
    await page.getByRole('button', { name: 'Save customer' }).click();
    await waitForLivewire(page);

    console.log('6. Send step');
    await page.getByRole('button', { name: 'Next: Send →' }).click();
    await waitForLivewire(page);
    await page.locator('#asn-number').fill(ASN);
    await page.locator('#customer-po').fill(PO);
    await page.locator('#dscsa-affirm').check();
    await page.getByRole('button', { name: 'Save references' }).click();
    await waitForLivewire(page);

    console.log('7. Send shipment');
    await page.getByRole('button', { name: /Send [Ss]hipment/ }).click();
    await waitForLivewire(page);
    await sleep(1500);
    const modal = page.locator('.fi-modal-window, [role="dialog"]').filter({ hasText: 'Send this shipment' });
    await modal.first().waitFor({ timeout: 15000 });
    await modal.getByRole('textbox', { name: /password/i }).fill(PASSWORD);
    await modal.getByRole('button', { name: /Send [Ss]hipment/ }).click();
    await waitForLivewire(page);
    await sleep(3000);

    const statusBadge = await page.locator('.badge-lg').first().textContent();
    console.log('   Status:', statusBadge?.trim());
    console.log('   Final URL:', page.url());

    await page.screenshot({ path: '/tmp/demo2-ship-result.png', fullPage: true });
    console.log('   Screenshot: /tmp/demo2-ship-result.png');

    if (!/completed/i.test(statusBadge ?? '')) {
      const body = await page.locator('body').innerText();
      if (/error|blocked|failed/i.test(body)) {
        console.error('Page may show errors:', body.slice(0, 2000));
      }
      throw new Error(`Expected completed status, got: ${statusBadge}`);
    }

    console.log(JSON.stringify({ success: true, sessionId, asn: ASN }));
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
