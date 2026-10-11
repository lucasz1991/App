# Location-only native API

All endpoints use HTTPS `POST /api/employee-location/{action}`, JSON `Content-Type` and `Accept` headers, without cookies. Redirects are rejected. The paired URL is an HTTPS origin without user info, path, query or fragment. Time fields are fractional ISO 8601 UTC values. Responses and the native HTTP session are not cached.

| Action | Authentication | Request | Successful response |
| --- | --- | --- | --- |
| `register` | None; one-use code | `code`, `installation_id` UUID, `platform` = `android` or `ios` | HTTP 201: `token`, `installation_id`, `server_time` |
| `start` | Device Bearer token | `code` = one-use 60-second handoff | `permit_id` UUID, `expires_at`, `server_time`, `next_sample_at`, `work_time_entry_id` |
| `permit` | Device Bearer token | `{}` | Same permit fields, deadline at most 120 seconds and no later than consent expiry |
| `points` | Device Bearer token | `permit_id`, `event_id` UUID, `latitude`, `longitude`, `accuracy_m`, `measured_at` | `accepted`, `duplicate`, `next_sample_at`, `server_time` |
| `stop` | Device Bearer token | `{}` | HTTP 204; no time-record or consent mutation |

Registration tokens are stored only in OS-protected credentials. Raw codes and coordinates are never logged or persisted in the companion. Only one native installation per employee can be active. Re-registration is controlled by the server's corporate device assignment validation.

The exact verified link is `https://<paired-host>/employee-location/open#code=<one-use-code>&server=<encoded-HTTPS-origin>`. The companion validates origin, port and exact path, reads the fragment in memory and still requires an explicit visible start. The `server` fragment item is optional; if supplied it must exactly match the existing pairing. Handoff never changes the paired origin. Neither the fallback web page nor the association files receive the fragment in the HTTP request.

Every renewed permit revalidates global enablement, employee state, corporate assignment, explanation version, today's consent and running unpaused work time. HTTP 401 clears local credentials. All other denied responses, malformed responses, transport failures and expiry stop collection locally; no automatic reconnect or offline upload is attempted.

The native app discards out-of-window fixes before uploading. The server independently validates permit, measurement time, cadence, accuracy and event identity. Retrying the same event ID is idempotent server-side, though version 1 deliberately does not retry location requests. GPS does not create, alter or prove work time.
