import { chromium } from 'playwright';

const BASE = 'https://demo2.internal.vatengi.com';
const SESSION_ID = process.env.SESSION_ID ?? '2876';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function waitForLivewire(page) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await sleep(800);
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  try {
    await page.goto(`${BASE}/login`);
    await page.getByRole('textbox', { name: /email/i }).fill('owner@demo.test');
    await page.getByRole('textbox', { name: /password/i }).fill('password');
    await page.getByRole('button', { name: /sign in|log in/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 });

    await page.goto(`${BASE}/scan-out?session=${SESSION_ID}&step=3`);
    await waitForLivewire(page);
    await page.screenshot({ path: '/tmp/demo2-before-send.png', fullPage: true });

    const sendBtn = page.getByRole('button', { name: /Send [Ss]hipment/ });
    console.log('Send enabled:', await sendBtn.isEnabled());
    await sendBtn.click();
    await waitForLivewire(page);
    await sleep(1500);

    const modal = page.locator('.fi-modal-window, [role="dialog"]').filter({ hasText: 'Send this shipment' });
    await modal.first().waitFor({ timeout: 15000 });
    await modal.getByRole('textbox', { name: /password/i }).fill('password');
    await modal.getByRole('button', { name: /Send [Ss]hipment/ }).click();
    await waitForLivewire(page);
    await sleep(5000);

    const statusBadge = await page.locator('.badge-lg').first().textContent();
    console.log('Status:', statusBadge?.trim());
    console.log('URL:', page.url());
  } finally {
    await browser.close();
  }
}

main();
