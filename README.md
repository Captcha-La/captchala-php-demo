# CaptchaLa Standalone Demo

Pure HTML + PHP integration demo for [CaptchaLa](https://captcha.la). No frameworks, no build step — drop into any web stack.

> **Open source under MIT.** Copy any file as a starting point for your own integration.

## Files

| File | What it does |
|---|---|
| `index.html` | Standalone HTML demo: 3 widget modes (popup / float / bind) using SDK from CDN |
| `verify.php` | Sample backend endpoint: validates a token via the CaptchaLa server API |
| `issue-token.php` | Sample backend endpoint: issues a short-lived server token (anti-replay binding) |
| `LICENSE` | MIT license — use freely |

## Quick start

1. Get a free app key + secret at [dash.captcha.la/register](https://dash.captcha.la/register) (10,000 verifications/month free).
2. Open `index.html` in your editor and replace `demo_app` with your `app_key`.
3. Open `verify.php` and `issue-token.php`, replace `YOUR_APP_KEY` and `YOUR_APP_SECRET`.
4. Drop the files on any web server with PHP.
5. Visit the page — all three widget modes work end-to-end.

## How the integration works

```
Browser                                  Your backend                    CaptchaLa API
   │                                          │                                │
   │ 1. Page load → init Captchala SDK        │                                │
   │ 2. (optional) GET /issue-token.php  ────>│                                │
   │                                          │ POST /v1/server-token/issue ──>│
   │ <───  short-lived server token  ─────────│ <───  token  ──────────────────│
   │ 3. User interacts → SDK onSuccess(token) │                                │
   │ 4. POST /verify.php { token, action } ──>│                                │
   │                                          │ POST /v1/challenge/verify  ───>│
   │                                          │ <───  { valid, risk_score }  ──│
   │ <─── allow/deny  ────────────────────────│                                │
```

The `verify.php` server-side check is **mandatory**. Client-side `onSuccess` is just a UX hint — anyone can fake it from DevTools.

## Endpoints used

- `POST https://apiv1.captcha.la/v1/server-token/issue` — pre-issue a session-bound token (optional, recommended for high-value flows)
- `POST https://apiv1.captcha.la/v1/challenge/verify` — server-side validate the token after `onSuccess`

Both endpoints take `X-App-Key` + `X-App-Secret` headers. Never expose the secret in browser code.

## Production checklist

- [ ] Replace demo `app_key` with your own
- [ ] Move `app_secret` to environment variable (not committed to source)
- [ ] Run `verify.php` over HTTPS only
- [ ] Add rate limiting to `verify.php` (per IP, ~30/min is reasonable)
- [ ] Log rejected tokens for audit
- [ ] Consider issuing server tokens (`issue-token.php`) for sign-up / payment flows

## Resources

- Full docs: [captcha.la/en/docs](https://captcha.la/en/docs)
- Native SDKs (iOS / Android / Flutter): [captcha.la/en/docs/sdk](https://captcha.la/en/docs/sdk)
- Pricing: [captcha.la/en/pricing](https://captcha.la/en/pricing)
- Support: [supply@captcha.la](mailto:supply@captcha.la)

---

License: MIT — see `LICENSE`.
