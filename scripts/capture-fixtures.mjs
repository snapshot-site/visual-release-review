import { chromium } from "@playwright/test";
import { fileURLToPath } from "node:url";

const baseURL = process.env.REVIEW_BASE_URL || "http://127.0.0.1:8088";
const browser = await chromium.launch();
try {
  const page = await browser.newPage({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
  });
  for (const state of ["before", "broken", "fixed", "uncertain"]) {
    await page.goto(`${baseURL}/fixtures/product.html?state=${state}`);
    await page.screenshot({
      path: fileURLToPath(
        new URL(`../public/fixtures/${state}.png`, import.meta.url),
      ),
    });
  }
} finally {
  await browser.close();
}
