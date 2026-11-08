// Minimal server: node server.mjs, then point your tracker at http://your-host:8787/?clickid={clickid}
import { createServer } from 'node:http';
import config from './config.mjs';
import { createGate } from './gate.mjs';

const gate = createGate(config);

createServer(async (req, res) => {
  const result = await gate.handle({
    method: req.method,
    url: req.url,
    headers: req.headers,
    remoteAddress: req.socket.remoteAddress,
  });
  res.writeHead(result.status, result.headers);
  res.end(result.body);
}).listen(process.env.PORT ?? 8787);
