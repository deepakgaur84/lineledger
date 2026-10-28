// LineLedger bank bridge.
//
// LineLedger (PHP) cannot talk to Actual Budget directly: Actual's client is a
// Node library. This service wraps it in three plain HTTP calls so LineLedger can
// list the accounts in an Actual budget, ask Actual to run its own bank sync, and
// read back the transactions.
//
//   GET  /health
//   POST /v1/accounts      { serverUrl, password, syncId, filePassword? }
//   POST /v1/transactions  { serverUrl, password, syncId, filePassword?, accountId,
//                            start, end, sync? }       (dates are YYYY-MM-DD)
//
// Every call carries the credentials it needs, so the bridge keeps no secrets of
// its own: LineLedger stores them encrypted and sends them over the private
// compose network, authenticated with BRIDGE_TOKEN. Actual's API works on one
// budget at a time, so requests are queued and run one after another.

import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import * as api from '@actual-app/api';

const PORT = Number(process.env.BRIDGE_PORT || 5007);
const DATA_DIR = process.env.BRIDGE_DATA_DIR || '/data';
const TOKEN = process.env.BRIDGE_TOKEN || '';
const MAX_BODY = 64 * 1024;

if (TOKEN.length < 16) {
  console.error('BRIDGE_TOKEN must be set to a secret of at least 16 characters.');
  process.exit(1);
}

class HttpError extends Error {
  constructor(status, message, code) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

// ---- one budget at a time -------------------------------------------------

let queue = Promise.resolve();

function exclusive(task) {
  const run = queue.then(task, task);
  queue = run.catch(() => {});
  return run;
}

// ---- helpers --------------------------------------------------------------

function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

function requireString(body, key) {
  const value = body[key];
  if (typeof value !== 'string' || value.trim() === '') {
    throw new HttpError(400, `Missing "${key}".`);
  }
  return value.trim();
}

function requireDate(body, key) {
  const value = requireString(body, key);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    throw new HttpError(400, `"${key}" must be YYYY-MM-DD.`);
  }
  return value;
}

function credentials(body) {
  return {
    serverUrl: requireString(body, 'serverUrl').replace(/\/+$/, ''),
    password: requireString(body, 'password'),
    syncId: requireString(body, 'syncId'),
    filePassword: typeof body.filePassword === 'string' && body.filePassword !== '' ? body.filePassword : null,
  };
}

// Turns Actual's errors into messages a person can act on.
function describe(error) {
  const code = error?.reason || error?.code || error?.message || '';
  const text = String(code);

  if (/invalid-password|unauthorized/i.test(text)) {
    return 'Actual rejected the server password.';
  }
  if (/budget-not-found|file-not-found/i.test(text)) {
    return 'Actual could not find that budget. Check the Sync ID (Settings → Show advanced settings).';
  }
  if (/missing-key|decrypt-failure|decrypt/i.test(text)) {
    return 'The budget is end-to-end encrypted. Enter its file password.';
  }
  if (/network-failure|ECONNREFUSED|ENOTFOUND|fetch failed/i.test(text)) {
    return 'The bridge could not reach the Actual server.';
  }
  if (/out-of-sync|version/i.test(text)) {
    return 'The Actual server and the bridge are on different versions. Keep them at the same version.';
  }

  return error?.message ? String(error.message) : 'Actual returned an error.';
}

async function withBudget(creds, work) {
  const key = crypto.createHash('sha256').update(`${creds.serverUrl}|${creds.syncId}`).digest('hex').slice(0, 24);
  const dataDir = path.join(DATA_DIR, key);
  await fs.mkdir(dataDir, { recursive: true });

  let initialised = false;

  try {
    await api.init({ dataDir, serverURL: creds.serverUrl, password: creds.password });
    initialised = true;
    await api.downloadBudget(creds.syncId, creds.filePassword ? { password: creds.filePassword } : undefined);

    return await work();
  } catch (error) {
    if (error instanceof HttpError) throw error;
    throw new HttpError(502, describe(error), error?.reason || error?.code || null);
  } finally {
    if (initialised) {
      try {
        await api.shutdown();
      } catch {
        // Nothing useful to do; the next request starts fresh.
      }
    }
  }
}

// ---- handlers -------------------------------------------------------------

async function accounts(body) {
  const creds = credentials(body);

  return exclusive(() =>
    withBudget(creds, async () => {
      const all = await api.getAccounts();

      return {
        accounts: all
          .filter((a) => !a.closed)
          .map((a) => ({ id: a.id, name: a.name, offBudget: Boolean(a.offbudget) })),
      };
    }),
  );
}

async function transactions(body) {
  const creds = credentials(body);
  const accountId = requireString(body, 'accountId');
  const start = requireDate(body, 'start');
  const end = requireDate(body, 'end');
  const sync = body.sync !== false;

  return exclusive(() =>
    withBudget(creds, async () => {
      if (sync) {
        try {
          await api.runBankSync({ accountId });
        } catch (error) {
          throw new HttpError(502, `Actual's bank sync failed: ${describe(error)}`, error?.reason || error?.code || null);
        }
      }

      const payees = new Map((await api.getPayees()).map((p) => [p.id, p.name]));
      const rows = await api.getTransactions(accountId, start, end);

      return {
        transactions: rows
          .filter((t) => !t.is_child)
          .map((t) => ({
            id: t.id,
            importedId: t.imported_id || null,
            date: t.date,
            amount: t.amount,
            payeeName: t.payee ? payees.get(t.payee) || null : null,
            importedPayee: t.imported_payee || null,
            notes: t.notes || null,
            cleared: Boolean(t.cleared),
          })),
      };
    }),
  );
}

// ---- server ---------------------------------------------------------------

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];

    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY) {
        reject(new HttpError(413, 'Request too large.'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      try {
        const text = Buffer.concat(chunks).toString('utf8');
        resolve(text === '' ? {} : JSON.parse(text));
      } catch {
        reject(new HttpError(400, 'Body must be JSON.'));
      }
    });
    req.on('error', reject);
  });
}

function send(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      return send(res, 200, { ok: true });
    }

    const auth = req.headers.authorization || '';
    if (!auth.startsWith('Bearer ') || !safeEqual(auth.slice(7), TOKEN)) {
      throw new HttpError(401, 'Unauthorized.');
    }

    if (req.method !== 'POST') {
      throw new HttpError(405, 'Use POST.');
    }

    const body = await readBody(req);

    if (req.url === '/v1/accounts') return send(res, 200, await accounts(body));
    if (req.url === '/v1/transactions') return send(res, 200, await transactions(body));

    throw new HttpError(404, 'Not found.');
  } catch (error) {
    const status = error instanceof HttpError ? error.status : 500;
    if (status >= 500) console.error(error);
    send(res, status, { error: error.message || 'Unexpected error.', code: error.code || null });
  }
});

// A bank sync can take a few minutes; do not let Node cut the connection first.
server.requestTimeout = 0;
server.headersTimeout = 30_000;
server.listen(PORT, '0.0.0.0', () => console.log(`bank bridge listening on :${PORT}`));

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
