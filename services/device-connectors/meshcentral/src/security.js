import crypto from 'node:crypto';

export function bearerMatches(headerValue, expectedToken) {
  if (typeof headerValue !== 'string' || !headerValue.startsWith('Bearer ')) {
    return false;
  }

  const submitted = Buffer.from(headerValue.slice(7), 'utf8');
  const expected = Buffer.from(expectedToken, 'utf8');
  return submitted.length === expected.length && crypto.timingSafeEqual(submitted, expected);
}

export function artifactSignature(timestamp, pathName, secret) {
  return crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.GET.${pathName}`, 'utf8')
    .digest('hex');
}

export function fingerprint(value) {
  return crypto.createHash('sha256').update(stableJson(value), 'utf8').digest('hex');
}

export function stableJson(value) {
  if (Array.isArray(value)) {
    return `[${value.map((item) => stableJson(item)).join(',')}]`;
  }
  if (value !== null && typeof value === 'object') {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`).join(',')}}`;
  }

  return JSON.stringify(value);
}

export function deterministicJobId(correlationId) {
  return `meshcentral:${crypto.createHash('sha256').update(correlationId, 'utf8').digest('hex').slice(0, 32)}`;
}

export function randomRequestId() {
  return crypto.randomUUID();
}

export function safeLog(logger, level, event, properties = {}) {
  const clean = {};
  for (const [key, value] of Object.entries(properties)) {
    if (/(token|secret|signature|password|key|artifact_url|command_line)/i.test(key)) {
      continue;
    }
    if (['string', 'number', 'boolean'].includes(typeof value) || value === null) {
      clean[key] = typeof value === 'string' ? value.slice(0, 500) : value;
    }
  }
  logger[level]?.(JSON.stringify({
    time: new Date().toISOString(),
    level,
    event,
    ...clean,
  }));
}
