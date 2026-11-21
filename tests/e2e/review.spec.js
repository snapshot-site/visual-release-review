const { test, expect } = require("@playwright/test");

test("shows the regression, images and independent readings", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByRole("button", { name: "Voir la régression" }).click();
  await expect(page.getByTestId("verdict")).toHaveText("Contredit");
  await expect(page.locator(".replay-notice")).toContainText(
    "sans appel aux agents",
  );
  await expect(
    page.getByRole("heading", { name: "Inspecteur", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Critique", exact: true }),
  ).toBeVisible();
  for (const image of await page.locator(".evidence-grid img").all()) {
    await expect(image).toBeVisible();
    await expect
      .poll(() =>
        image.evaluate(
          (img) =>
            img.complete &&
            img.naturalWidth === 390 &&
            img.naturalHeight === 844,
        ),
      )
      .toBeTruthy();
  }
  await expect(page.locator("body")).toContainText(
    "ne vérifie pas les interactions",
  );
  await expect(
    page.getByRole("link", { name: "Essayer Snapshot Site" }),
  ).toHaveAttribute("href", /utm_source=release-review/);
  await page.screenshot({
    path: test.info().outputPath("report.png"),
    fullPage: true,
  });
  await page.getByRole("link", { name: "Nouvelle comparaison" }).click();
  await expect(page.getByRole("heading", { level: 1 })).toContainText(
    "Un petit changement.",
  );
});

for (const [label, verdict] of [
  ["Version corrigée", "Confirmé"],
  ["Preuves insuffisantes", "Indéterminé"],
  ["Agents en désaccord", "Indéterminé"],
]) {
  test(`${label} produces ${verdict}`, async ({ page }) => {
    await page.goto("/");
    await page.getByRole("button", { name: label }).click();
    await expect(page.getByTestId("verdict")).toHaveText(verdict);
    if (label === "Agents en désaccord") {
      await expect(page.locator(".verdict-panel")).toContainText(
        "Les agents ne concluent pas la même chose",
      );
    }
  });
}

test("keeps submitted values and explains missing credentials", async ({
  page,
}) => {
  await page.goto("/");
  await expect(page.locator(".form-note")).toContainText("attend vos clés API");
  await page
    .getByLabel("Avant", { exact: false })
    .fill("https://example.com/before");
  await page
    .getByLabel("Après", { exact: false })
    .fill("https://example.com/after");
  await page
    .getByLabel("Qu’est-ce qu’on vérifie ?")
    .fill("Le bouton Acheter reste entièrement visible.");
  await page.getByRole("button", { name: "Comparer mes pages" }).click();
  await expect(page.getByRole("alert")).toContainText("nécessite les clés");
  await expect(page.getByLabel("Avant", { exact: false })).toHaveValue(
    "https://example.com/before",
  );
  await expect(
    page.getByRole("button", { name: "Comparer mes pages" }),
  ).toBeEnabled();
  await expect(page.getByTestId("verdict")).toHaveCount(0);
});

test("rejects a post without the session token", async ({ request }) => {
  const response = await request.post("/demo/broken", {
    form: { _token: "invalid" },
  });
  expect(response.status()).toBe(403);
});

test("fits the viewport and keeps form labels accessible", async ({ page }) => {
  await page.goto("/");
  const logo = page.getByRole("img", { name: "Snapshot Site", exact: true });
  await expect(logo).toBeVisible();
  await expect
    .poll(() =>
      logo.evaluate((image) => image.complete && image.naturalWidth === 387),
    )
    .toBeTruthy();
  await expect(logo).toHaveJSProperty(
    "currentSrc",
    "http://127.0.0.1:8089/images/logo.avif",
  );
  for (const path of [
    "/images/logo.png",
    "/images/favicon.png",
    "/images/favicon_created_by_ss.ico",
    "/images/icon-512x512.png",
  ]) {
    const response = await page.request.get(path);
    expect(response.ok(), path).toBeTruthy();
    expect(response.headers()["content-type"], path).toMatch(/^image\//);
  }
  await expect(
    page.locator('link[rel="icon"][type="image/x-icon"]'),
  ).toHaveAttribute("href", "/images/favicon_created_by_ss.ico");
  const manifestPath = await page
    .locator('link[rel="manifest"]')
    .getAttribute("href");
  const manifestResponse = await page.request.get(manifestPath);
  expect(manifestResponse.ok()).toBeTruthy();
  const manifest = await manifestResponse.json();
  expect(manifest.icons).toContainEqual(
    expect.objectContaining({
      src: "/images/icon-512x512.png",
      sizes: "512x512",
    }),
  );
  await page.screenshot({
    path: test.info().outputPath("home.png"),
    fullPage: true,
  });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await expect(page.getByLabel("Qu’est-ce qu’on vérifie ?")).toBeVisible();
  await page.getByRole("button", { name: "Voir la régression" }).click();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
});

test("fixture contains a real mobile occlusion and a harmless color change", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/fixtures/product.html?state=before");
  const visible = () =>
    page.locator(".buy").evaluate((button) => {
      const rect = button.getBoundingClientRect();
      const top = document.elementFromPoint(
        rect.x + rect.width / 2,
        rect.y + rect.height / 2,
      );
      return button.contains(top) || button === top;
    });
  expect(await visible()).toBeTruthy();
  await page.goto("/fixtures/product.html?state=broken");
  expect(await visible()).toBeFalsy();
  await page.goto("/fixtures/product.html?state=fixed");
  expect(await visible()).toBeTruthy();
});

test("switches between French and English across the complete review", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByRole("link", { name: "English", exact: true }).click();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  await expect(page.getByRole("heading", { level: 1 })).toContainText(
    "One small change.",
  );
  await expect(page.getByLabel("What should we check?")).toBeVisible();
  await expect(
    page.getByRole("link", { name: "English", exact: true }),
  ).toHaveAttribute("aria-current", "page");
  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page.screenshot({
    path: test.info().outputPath("home-en.png"),
    fullPage: true,
  });
  const manifest = await (await page.request.get("/app.webmanifest")).json();
  expect(manifest.lang).toBe("en");
  expect(manifest.start_url).toBe("/?lang=en");
  await page.getByRole("button", { name: "See the regression" }).click();
  await expect(page.getByTestId("verdict")).toHaveText("Contradicted");
  await expect(page.locator(".agent-card").first()).toContainText(
    "the fixed banner covers",
  );
  await expect(page.locator(".verdict-panel")).toContainText(
    "Both reviews agree",
  );
  await expect(
    page.getByRole("img", {
      name: "Mobile screenshot before the change",
      exact: true,
    }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page.screenshot({
    path: test.info().outputPath("report-en.png"),
    fullPage: true,
  });
  await page.getByRole("link", { name: "New comparison" }).click();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  await expect(page.locator(".form-note")).toContainText("need your API keys");
  await page
    .getByLabel("Before", { exact: false })
    .fill("https://example.com/before");
  await page
    .getByLabel("After", { exact: false })
    .fill("https://example.com/after");
  await page
    .getByLabel("What should we check?")
    .fill("The Buy button remains fully visible.");
  await page.getByRole("button", { name: "Compare my pages" }).click();
  await expect(page.getByRole("alert")).toContainText(
    "requires Snapshot Site and model API keys",
  );
  await expect(
    page.getByRole("button", { name: "Compare my pages" }),
  ).toBeEnabled();
  await page.getByRole("link", { name: "Français", exact: true }).click();
  await expect(page.locator("html")).toHaveAttribute("lang", "fr");
  await expect(page.getByRole("heading", { level: 1 })).toContainText(
    "Un petit changement.",
  );
});

for (const [locale, home, missing, method] of [
  [
    "fr",
    "Retour à l’accueil",
    "Cette page joue à cache-cache.",
    "Bonne porte, mauvaise poignée.",
  ],
  [
    "en",
    "Back to home",
    "This page is playing hide-and-seek.",
    "Right door, wrong handle.",
  ],
]) {
  test(`handles 404 and 405 with a usable ${locale} error page`, async ({
    page,
  }) => {
    for (const [path, status, heading] of [
      ["/page-absente", 404, missing],
      ["/review", 405, method],
    ]) {
      const response = await page.goto(`${path}?lang=${locale}`);
      expect(response.status()).toBe(status);
      expect(response.headers()["content-language"]).toBe(locale);
      if (status === 405) expect(response.headers()["allow"]).toBe("POST");
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(heading);
      await expect(page.locator(".error-illustration")).toBeVisible();
      await expect(page.locator("body")).not.toContainText("Stack Trace");
      await expect(page.locator("body")).not.toContainText("vendor/");
      expect(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth,
        ),
      ).toBeTruthy();
      await page.screenshot({
        path: test.info().outputPath(`error-${status}-${locale}.png`),
        fullPage: true,
      });
      await page.getByRole("link", { name: home, exact: true }).click();
      await expect(
        page.getByRole("button", {
          name: locale === "fr" ? "Comparer mes pages" : "Compare my pages",
        }),
      ).toBeVisible();
      await expect(page.locator("html")).toHaveAttribute("lang", locale);
    }
  });
}
