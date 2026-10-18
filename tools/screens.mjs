// Тестер: открывает страницы в настоящем браузере (Chromium) на ширине
// телефона и компьютера, делает скриншоты, ловит ошибки JS и незагруженные файлы.
//
// Запуск:  node tools/screens.mjs index.html contacts.html
// Результат: out/screens/<страница>-phone.png и -desktop.png + отчёт в консоли.
//
// Безопасность: запросы к formsubmit.co (отправка заявок на почту) и к
// Яндекс.Метрике блокируются — тесты не шлют письма и не портят статистику.

import { chromium } from "playwright";
import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.join(ROOT, "out", "screens");
fs.mkdirSync(OUT, { recursive: true });

const pages = process.argv.slice(2);
if (!pages.length) pages.push("index.html");

const TYPES = { ".html": "text/html; charset=utf-8", ".css": "text/css", ".js": "text/javascript",
  ".svg": "image/svg+xml", ".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg",
  ".webp": "image/webp", ".gif": "image/gif", ".pdf": "application/pdf", ".xml": "application/xml",
  ".woff": "font/woff", ".woff2": "font/woff2", ".ttf": "font/ttf", ".ico": "image/x-icon" };

// Маленький локальный сервер, который ведёт себя как хостинг.
const server = http.createServer((req, res) => {
  let p = decodeURIComponent(new URL(req.url, "http://x").pathname);
  let file = path.join(ROOT, path.normalize(p));
  if (!file.startsWith(ROOT)) { res.writeHead(403).end(); return; }
  if (fs.existsSync(file) && fs.statSync(file).isDirectory()) file = path.join(file, "index.html");
  if (!fs.existsSync(file)) { res.writeHead(404).end("not found"); return; }
  res.writeHead(200, { "Content-Type": TYPES[path.extname(file).toLowerCase()] || "application/octet-stream" });
  fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(0, "127.0.0.1", r));
const base = `http://127.0.0.1:${server.address().port}/`;

const launch = { headless: true };
if (fs.existsSync("/opt/pw-browsers/chromium")) launch.executablePath = "/opt/pw-browsers/chromium";
let browser;
try { browser = await chromium.launch(launch); } catch { browser = await chromium.launch({ headless: true }); }

const VIEWS = [
  { name: "phone", viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
  { name: "desktop", viewport: { width: 1440, height: 900 } },
];

let problems = 0;
for (const page of pages) {
  for (const v of VIEWS) {
    const ctx = await browser.newContext(v);
    await ctx.route(/formsubmit\.co|mc\.yandex\.ru|metrika/, (r) => r.abort());
    const tab = await ctx.newPage();
    const issues = [];
    tab.on("pageerror", (e) => issues.push(`ошибка JS: ${e.message}`));
    tab.on("console", (m) => { if (m.type() === "error" && !/ERR_FAILED|blocked/i.test(m.text())) issues.push(`console: ${m.text()}`); });
    tab.on("response", (r) => { if (r.url().startsWith(base) && r.status() >= 400) issues.push(`не загрузился ${r.status()}: ${r.url().replace(base, "/")}`); });
    try {
      await tab.goto(base + page, { waitUntil: "networkidle", timeout: 30000 });
    } catch (e) {
      issues.push(`страница не открылась: ${e.message.split("\n")[0]}`);
    }
    // Прокручиваем до конца, чтобы сработали анимации появления и ленивые картинки.
    await tab.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); }
      window.scrollTo(0, 0);
    }).catch(() => {});
    await tab.waitForTimeout(400);
    const overflow = await tab.evaluate(() => document.documentElement.scrollWidth - window.innerWidth).catch(() => 0);
    if (overflow > 2) issues.push(`горизонтальная прокрутка: страница шире экрана на ${overflow}px`);
    const shot = path.join(OUT, `${page.replace(/[\/\\]/g, "__")}-${v.name}.png`);
    await tab.screenshot({ path: shot, fullPage: true }).catch(() => tab.screenshot({ path: shot }));
    console.log(`\n== ${page} [${v.name}] → ${path.relative(ROOT, shot)}`);
    if (issues.length) { problems += issues.length; for (const i of [...new Set(issues)]) console.log("  ✗ " + i); }
    else console.log("  ✓ без ошибок");
    await ctx.close();
  }
}
await browser.close();
server.close();
console.log(`\nИтого проблем: ${problems}`);
process.exit(problems ? 1 : 0);
