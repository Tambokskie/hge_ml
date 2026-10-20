// Retakes the screenshots used by the Help page (admin/help.php) into assets/images/help/.
//
//   node tools/help-screenshots/capture.mjs [--only name,name] [--themes light,dark] [--format webp|png] [--out dir]
//
// What it does, all on this machine: fills a separate database (hge_help_shots) with made-up data, serves the app
// against it with PHP's built-in server on 127.0.0.1:8099, drives headless Chrome over the DevTools protocol to
// sign in and photograph each page, then stops the server and drops the database. The real database is never used.
//
// Needs: PHP and MySQL/MariaDB (root, no password, as the tests use) and Chrome. Override the executables with the
// PHP and CHROME environment variables. Node 22+ (global WebSocket and fetch).
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '..', '..');
const repoName = path.basename(repo);
const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => (a.startsWith('--') ? [...acc, [a.slice(2), all[i + 1]]] : acc), []));
const ONLY = args.only ? args.only.split(',') : null;
const THEMES = (args.themes || 'light,dark').split(',');
const FMT = args.format || 'webp';
const OUT = path.resolve(args.out || path.join(repo, 'assets', 'images', 'help'));

const PORT = 8099;
const CDP_PORT = 9333;
const BASE = `http://127.0.0.1:${PORT}`;
const APP = `${BASE}/${repoName}`;
const pick = (envName, candidates) => process.env[envName] || candidates.find((c) => c === envName || fs.existsSync(c)) || candidates[candidates.length - 1];
const PHP = pick('PHP', ['C:/xampp/php/php.exe', 'php']);
const CHROME = pick('CHROME', ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', 'google-chrome']);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// [file name, user id (1 administrator, 2 plain user), path, window height, optional script run after load, settle ms, upload to stage]
const W = 1280;
const shots = [
    ['dashboard', 2, '/admin/dashboard.php', 860, null, 2800],
    ['lookup', 2, '/admin/lookup.php', 820, null, 1200],
    ['lookup-apply', 2, '/admin/lookup.php', 820, `(function(){var b=document.querySelector('.apply-graduate-btn'); if(b){b.click();return 'clicked';} return 'no button';})()`, 1200],
    ['lookup-admin', 1, '/admin/lookup.php', 820, null, 1200],
    ['upload-pdf', 1, '/admin/upload.php', 900, null, 1500],
    ['upload-template', 1, '/admin/upload.php', 900, `(function(){var t=[].slice.call(document.querySelectorAll('button,a,[role=tab]')).filter(function(x){return /EXCEL TEMPLATE/i.test(x.textContent)})[0]; if(t){t.click();return 'tab';} return 'no tab';})()`, 1000],
    ['review', 1, '/admin/review.php?upload_id=8', 900, null, 1500, 8],
    ['school-records', 1, '/admin/school_records.php', 820, null, 1200],
    ['view-upload', 1, '/admin/view_upload.php?id=2', 900, null, 1200],
    ['admin-tools', 1, '/admin/admin_tools.php', 820, null, 1200],
    ['bulk-update', 1, '/admin/admin_tools.php', 900, `(function(){var h=[].slice.call(document.querySelectorAll('h2,h3')).filter(function(x){return /BULK UPDATE/i.test(x.textContent)})[0]; if(h){var c=h.closest('section,div.rounded-2xl,div.rounded-xl')||h; c.scrollIntoView({block:'start'}); window.scrollBy(0,-90); return 'scrolled';} return 'no card';})()`, 1200],
    ['users', 1, '/admin/users.php', 760, null, 1200],
    ['schools', 1, '/admin/schools.php', 800, null, 1200],
    ['ocr-courses', 1, '/admin/ocr_courses.php', 700, null, 1200],
    ['duplicates', 1, '/admin/duplicates.php', 900, null, 1200],
    ['recycle-bin', 1, '/admin/recycle_bin.php', 760, null, 1200],
    ['activity-log', 1, '/admin/activity_log.php', 820, null, 1200],
];

const seed = (...extra) => execFileSync(PHP, [path.join(here, 'seed.php'), ...extra], { stdio: ['ignore', 'inherit', 'inherit'] });
let server = null;
let chrome = null;
let profile = null;

async function cleanup() {
    try { chrome?.kill(); } catch (e) { /* already gone */ }
    try { server?.kill(); } catch (e) { /* already gone */ }
    if (profile) { try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* in use: harmless temp folder */ } }
    try { seed('drop'); } catch (e) { console.error('Could not drop hge_help_shots: ' + e.message); }
}

async function main() {
    seed();
    server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, '-t', path.dirname(repo)], {
        stdio: 'ignore',
        env: { ...process.env, DB_NAME: 'hge_help_shots', APP_BASE_URL: `/${repoName}` },
    });
    for (let i = 0; i < 50; i++) {
        try { await fetch(`${BASE}/${repoName}/tools/help-screenshots/login.php`); break; } catch (e) { await sleep(200); }
    }

    profile = fs.mkdtempSync(path.join(os.tmpdir(), 'help-shots-'));
    chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--hide-scrollbars', `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${profile}`, `--window-size=${W},900`, 'about:blank'], { stdio: 'ignore' });
    let wsUrl = null;
    for (let i = 0; i < 50 && !wsUrl; i++) {
        try { wsUrl = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json`)).json()).find((t) => t.type === 'page')?.webSocketDebuggerUrl; } catch (e) { /* not up yet */ }
        if (!wsUrl) await sleep(200);
    }
    if (!wsUrl) throw new Error('Chrome did not start (set the CHROME environment variable to its path).');

    const ws = new WebSocket(wsUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    let id = 0;
    const pending = new Map();
    const waiters = [];
    ws.addEventListener('message', (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); } else if (msg.method) { waiters.forEach((w) => w(msg)); }
    });
    const send = (method, params = {}) => new Promise((res, rej) => {
        const myId = ++id;
        pending.set(myId, (m) => (m.error ? rej(new Error(method + ': ' + JSON.stringify(m.error))) : res(m.result)));
        ws.send(JSON.stringify({ id: myId, method, params }));
    });
    const loaded = () => new Promise((res) => { const w = (m) => { if (m.method === 'Page.loadEventFired') { waiters.splice(waiters.indexOf(w), 1); res(); } }; waiters.push(w); });
    const go = async (url) => { const l = loaded(); await send('Page.navigate', { url }); await Promise.race([l, sleep(15000)]); };
    const evalJs = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })).result?.value;
    await send('Page.enable');
    await send('Runtime.enable');

    fs.mkdirSync(OUT, { recursive: true });
    const login = (uid, stage) => go(`${APP}/tools/help-screenshots/login.php?uid=${uid}${stage ? '&stage=' + stage : ''}`);
    const report = [];
    for (const theme of THEMES) {
        await login(1);
        await evalJs(`localStorage.setItem('hge_theme','${theme}'); 1`);
        let currentUid = 1;
        for (const [name, uid, route, height, setup, settle, stage] of shots) {
            if (ONLY && !ONLY.includes(name)) continue;
            if (currentUid !== uid || stage) { await login(uid, stage); currentUid = uid; }
            await send('Emulation.setDeviceMetricsOverride', { width: W, height, deviceScaleFactor: 1, mobile: false });
            await go(APP + route);
            await sleep(400);
            const setupResult = setup ? await evalJs(setup) : null;
            await sleep(settle);
            const isDark = await evalJs(`document.documentElement.classList.contains('dark')`);
            const shot = await send('Page.captureScreenshot', { format: FMT, quality: 82 });
            const file = path.join(OUT, `${name}-${theme}.${FMT === 'jpeg' ? 'jpg' : FMT}`);
            fs.writeFileSync(file, Buffer.from(shot.data, 'base64'));
            report.push(`${theme.padEnd(5)} ${name.padEnd(16)} ${String(fs.statSync(file).size).padStart(7)} bytes  dark=${isDark}${setupResult ? '  (' + setupResult + ')' : ''}`);
        }
    }
    console.log(report.join('\n'));
    ws.close();
}

try {
    await main();
} catch (e) {
    console.error(e);
    process.exitCode = 1;
} finally {
    await cleanup();
}
process.exit(process.exitCode || 0);
