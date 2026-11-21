const { defineConfig, devices } = require("@playwright/test");

module.exports = defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: "list",
  use: {
    baseURL: "http://127.0.0.1:8089",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"] } },
    {
      name: "mobile",
      use: { ...devices["iPhone 13"], defaultBrowserType: "chromium" },
    },
  ],
  webServer: {
    command:
      "php -d variables_order=EGPCS -S 127.0.0.1:8089 -t public public/router.php",
    url: "http://127.0.0.1:8089",
    reuseExistingServer: false,
    env: {
      APP_ENV: "test",
      APP_DEBUG: "1",
      OPENAI_API_KEY: "",
      SNAPSHOT_SITE_API_KEY: "",
    },
    timeout: 30000,
  },
});
