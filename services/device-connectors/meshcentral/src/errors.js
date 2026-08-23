export class ConnectorError extends Error {
  constructor(message, { statusCode = 500, code = 'connector_error', retryable = false, cause } = {}) {
    super(message, { cause });
    this.name = 'ConnectorError';
    this.statusCode = statusCode;
    this.code = code;
    this.retryable = retryable;
  }
}

export function publicError(error) {
  if (error instanceof ConnectorError) {
    return {
      statusCode: error.statusCode,
      body: {
        error: error.code,
        message: error.message,
        retryable: error.retryable,
      },
    };
  }

  return {
    statusCode: 500,
    body: {
      error: 'internal_error',
      message: 'Der Connector konnte die Anfrage nicht sicher abschließen.',
      retryable: false,
    },
  };
}
