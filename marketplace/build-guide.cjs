// Render user-guide.html to user-guide.pdf with the Chromium Playwright ships with.
//   node plugins/magento/marketplace/build-guide.cjs
const path = require('node:path');
const { chromium } = require('playwright');
(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage();
    await page.goto(`file://${path.join(__dirname, 'user-guide.html')}`, { waitUntil: 'networkidle' });
    await page.pdf({
        path: path.join(__dirname, 'user-guide.pdf'),
        format: 'A4',
        printBackground: true,
        displayHeaderFooter: true,
        headerTemplate: '<span></span>',
        footerTemplate:
            '<div style="font:8px sans-serif;color:#63635E;width:100%;text-align:center">Intents Checkout for Magento · support@intents.fi · <span class="pageNumber"></span>/<span class="totalPages"></span></div>',
        margin: { top: '18mm', bottom: '20mm', left: '18mm', right: '18mm' },
    });
    await browser.close();
})();
