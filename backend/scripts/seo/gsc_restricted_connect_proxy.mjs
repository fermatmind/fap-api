#!/usr/bin/env node

import net from 'node:net';
import { pathToFileURL } from 'node:url';

const ALLOWED_TARGETS = new Set([
  'oauth2.googleapis.com:443',
  'searchconsole.googleapis.com:443',
]);

const MAX_HEADER_BYTES = 8192;
const SOCKET_TIMEOUT_MS = 15_000;

export function parseConnectTarget(requestLine) {
  const match = /^CONNECT ([a-z0-9.-]+):(\d{1,5}) HTTP\/1\.[01]$/i.exec(requestLine);
  if (match === null) {
    return null;
  }

  const port = Number.parseInt(match[2], 10);
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    return null;
  }

  return {
    host: match[1].toLowerCase(),
    port,
    authority: `${match[1].toLowerCase()}:${port}`,
  };
}

export function isAllowedTarget(target) {
  return target !== null && ALLOWED_TARGETS.has(target.authority);
}

function reject(socket, status, reason) {
  socket.end(`HTTP/1.1 ${status} ${reason}\r\nConnection: close\r\n\r\n`);
}

export function createRestrictedProxy() {
  return net.createServer((client) => {
    let header = Buffer.alloc(0);
    client.setTimeout(SOCKET_TIMEOUT_MS, () => client.destroy());
    client.on('error', () => {});

    const onData = (chunk) => {
      header = Buffer.concat([header, chunk]);
      if (header.length > MAX_HEADER_BYTES) {
        client.off('data', onData);
        reject(client, 431, 'Request Header Fields Too Large');
        return;
      }

      const headerEnd = header.indexOf('\r\n\r\n');
      if (headerEnd < 0) {
        return;
      }

      client.off('data', onData);
      const lineEnd = header.indexOf('\r\n');
      const requestLine = header.subarray(0, lineEnd).toString('ascii');
      const target = parseConnectTarget(requestLine);
      if (!isAllowedTarget(target)) {
        reject(client, 403, 'Forbidden');
        return;
      }

      const upstream = net.connect(target.port, target.host);
      upstream.setTimeout(SOCKET_TIMEOUT_MS, () => upstream.destroy());
      upstream.on('error', () => client.destroy());
      upstream.once('connect', () => {
        client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        const remainder = header.subarray(headerEnd + 4);
        if (remainder.length > 0) {
          upstream.write(remainder);
        }
        client.pipe(upstream);
        upstream.pipe(client);
      });
    };

    client.on('data', onData);
  });
}

function portFromArgs(args) {
  const raw = args.find((value) => value.startsWith('--port='))?.slice('--port='.length) ?? '18443';
  const port = Number.parseInt(raw, 10);
  if (!/^\d+$/.test(raw) || port < 1024 || port > 65535) {
    throw new Error('port_must_be_between_1024_and_65535');
  }

  return port;
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const port = portFromArgs(process.argv.slice(2));
  const server = createRestrictedProxy();
  server.on('error', () => {
    process.stderr.write('restricted_proxy_failed\n');
    process.exitCode = 1;
  });
  server.listen(port, '127.0.0.1', () => {
    process.stdout.write('restricted_proxy_ready\n');
  });

  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => server.close(() => process.exit(0)));
  }
}
