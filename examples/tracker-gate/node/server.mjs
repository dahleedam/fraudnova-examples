// Minimal server: node server.mjs, then point your tracker at http://your-host:8787/?clickid={clickid}
import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';
import { createGate } from './gate.mjs';

/**
 * Turns the gate into an http request handler. Nothing the gate throws can crash the
 * process: any unexpected error sends the visitor to the safe page and logs one line.
 */
export function makeHandler(gate, fallbackUrl) {
  return async (req, res) => {
    try {
      const result = await gate.handle({
        method: req.method,
        url: req.url,
        headers: req.headers,
        remoteAddress: req.socket.remoteAddress,
      });
      res.writeHead(result.status, result.headers);
      res.end(result.body);
    } catch (e) {
      console.error(`fraudnova-gate: unexpected error (${e?.name ?? 'Error'}), sent to the safe page`);
      try {
        res.writeHead(302, { Location: fallbackUrl, 'Cache-Control': 'no-store' });
        res.end();
      } catch {
        // The connection is already gone.
      }
    }
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const config = (await import('./config.mjs')).default;
  const gate = createGate(config);
  createServer(makeHandler(gate, config.routes.default.safe)).listen(process.env.PORT ?? 8787);
}
