import http from 'node:http';
import { ConnectorError, publicError } from './errors.js';
import { bearerMatches, randomRequestId, safeLog } from './security.js';

export function createConnectorServer(config, service, logger = console) {
  const server = http.createServer(async (request, response) => {
    const requestId = randomRequestId();
    const startedAt = Date.now();
    try {
      const result = await routeRequest(config, service, request);
      sendJson(config, response, result.statusCode, result.body, requestId);
      safeLog(logger, 'info', 'connector.request.completed', {
        request_id: requestId,
        method: request.method,
        path: safePath(request.url),
        status_code: result.statusCode,
        duration_ms: Date.now() - startedAt,
      });
    } catch (error) {
      const result = publicError(error);
      sendJson(config, response, result.statusCode, result.body, requestId);
      safeLog(logger, result.statusCode >= 500 ? 'error' : 'warn', 'connector.request.failed', {
        request_id: requestId,
        method: request.method,
        path: safePath(request.url),
        status_code: result.statusCode,
        error_code: result.body.error,
        duration_ms: Date.now() - startedAt,
      });
    }
  });

  server.requestTimeout = config.server.requestTimeoutMs;
  server.headersTimeout = Math.min(config.server.requestTimeoutMs, 15000);
  server.keepAliveTimeout = 5000;
  server.maxRequestsPerSocket = 100;

  return server;
}

async function routeRequest(config, service, request) {
  if (!bearerMatches(request.headers.authorization, config.auth.bearerToken)) {
    throw new ConnectorError('Authentifizierung erforderlich.', {
      statusCode: 401,
      code: 'unauthenticated',
    });
  }

  let url;
  try {
    url = new URL(request.url, 'http://connector.invalid');
  } catch {
    throw new ConnectorError('Ungültiger Requestpfad.', { statusCode: 400, code: 'invalid_request' });
  }
  if (url.search !== '' || url.hash !== '') {
    throw new ConnectorError('Connector-Endpunkte akzeptieren keine Query oder Fragmente.', {
      statusCode: 400,
      code: 'invalid_request_target',
    });
  }

  if (url.pathname === '/v1/health') {
    requireMethod(request, 'GET');
    rejectRequestBody(request);
    return { statusCode: 200, body: await service.health() };
  }
  if (url.pathname === '/v1/enrollments') {
    requireMethod(request, 'POST');
    return { statusCode: 200, body: await service.prepareEnrollment(await readJsonBody(request, config.server.requestBodyBytes)) };
  }
  if (url.pathname === '/v1/commands') {
    requireMethod(request, 'POST');
    return { statusCode: 200, body: await service.dispatchCommand(await readJsonBody(request, config.server.requestBodyBytes)) };
  }

  throw new ConnectorError('Connector-Endpunkt nicht gefunden.', {
    statusCode: 404,
    code: 'not_found',
  });
}

function requireMethod(request, expected) {
  if (request.method !== expected) {
    throw new ConnectorError('HTTP-Methode für diesen Endpunkt nicht erlaubt.', {
      statusCode: 405,
      code: 'method_not_allowed',
    });
  }
}

function rejectRequestBody(request) {
  const length = request.headers['content-length'];
  if ((typeof length === 'string' && length !== '0') || request.headers['transfer-encoding']) {
    throw new ConnectorError('GET /v1/health akzeptiert keinen Requestbody.', {
      statusCode: 400,
      code: 'unexpected_request_body',
    });
  }
}

async function readJsonBody(request, maximumBytes) {
  const contentType = request.headers['content-type'];
  if (typeof contentType !== 'string' || !/^application\/json(?:\s*;\s*charset=utf-8)?$/i.test(contentType)) {
    throw new ConnectorError('Content-Type application/json ist erforderlich.', {
      statusCode: 415,
      code: 'unsupported_media_type',
    });
  }
  const declaredLength = request.headers['content-length'];
  if (typeof declaredLength === 'string') {
    if (!/^\d+$/.test(declaredLength) || Number.parseInt(declaredLength, 10) > maximumBytes) {
      throw new ConnectorError('Der Requestbody überschreitet das Limit.', {
        statusCode: 413,
        code: 'request_too_large',
      });
    }
  }

  const chunks = [];
  let bytes = 0;
  for await (const chunk of request) {
    bytes += chunk.length;
    if (bytes > maximumBytes) {
      request.destroy();
      throw new ConnectorError('Der Requestbody überschreitet das Limit.', {
        statusCode: 413,
        code: 'request_too_large',
      });
    }
    chunks.push(Buffer.from(chunk));
  }
  if (bytes === 0) {
    throw new ConnectorError('Ein JSON-Requestbody ist erforderlich.', {
      statusCode: 400,
      code: 'missing_request_body',
    });
  }

  try {
    const value = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error('JSON object required');
    }
    return value;
  } catch (error) {
    throw new ConnectorError('Der Requestbody ist kein gültiges JSON-Objekt.', {
      statusCode: 400,
      code: 'invalid_json',
      cause: error,
    });
  }
}

function sendJson(config, response, statusCode, value, requestId) {
  let encoded = JSON.stringify(value);
  if (Buffer.byteLength(encoded) > config.server.responseBodyBytes) {
    statusCode = 500;
    encoded = JSON.stringify({
      error: 'response_too_large',
      message: 'Die Connector-Antwort überschreitet das sichere Limit.',
      retryable: false,
    });
  }
  response.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(encoded),
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
    'X-Frame-Options': 'DENY',
    'Referrer-Policy': 'no-referrer',
    'X-Request-ID': requestId,
    ...(statusCode === 401 ? { 'WWW-Authenticate': 'Bearer realm="railtime-device-connector"' } : {}),
  });
  response.end(encoded);
}

function safePath(rawUrl) {
  if (typeof rawUrl !== 'string') return '';
  const index = rawUrl.indexOf('?');
  return (index >= 0 ? rawUrl.slice(0, index) : rawUrl).slice(0, 200);
}
