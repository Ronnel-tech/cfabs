# Google Sign-In

The backend uses Google Identity Services ID tokens for **sign-in only**. There
is no Google API access, automatic registration, email-based account matching,
or role assignment. School Google accounts and personal Gmail are supported.

## Local setup

1. Run `composer install` in `Lavalust/` (PHP 8.2+, OpenSSL, cURL, PDO MySQL).
   The maintained `firebase/php-jwt` dependency verifies Google's RS256 signatures;
   application sessions still use LavaLust's existing Api token methods.
2. Run `php lava migration run`. Migration `009_create_google_identities.php`
   creates identities and expiring, single-use login/link challenges. Preserve
   previous migrations and existing account data.
3. Set `GOOGLE_CLIENT_ID` in the backend's ignored `.env` to the **Web application**
   OAuth client ID. No Google client secret belongs in either application.
   An empty value disables Google Sign-In while campus-password login remains available.
4. In Google Cloud Console, select that OAuth client and add these **Authorized
   JavaScript origins** (no paths or trailing slashes):
   - `http://127.0.0.1:5173`
   - `http://localhost:5173`
   This JavaScript callback flow does not require an authorized redirect URI.
   Configure the consent-screen audience/testing users as required by Google.
5. Start the backend on port 3000 and frontend on port 5173 using the existing
   development commands. Production origins/deployment remain deferred.

Google's setup and verification requirements are described in the
[client setup guide](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid)
and [ID token verification guide](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

## Account workflow

Sign in with the campus password first. In **My account → Google Sign-In**, enter
the current campus password and choose the Google account to link. The backend
verifies the Google signature, issuer, audience, expiry, verified email and nonce,
then associates the stable Google `sub` with the campus user. A Google identity
and a campus user can each have only one link. Matching email addresses alone
never authorize linking.

Future Google sign-ins issue the normal campus tokens only while the linked
campus account is active. Live campus permissions remain authoritative. Unlinking
requires password confirmation and prevents future Google sign-ins; it does not
terminate existing sessions. The campus password continues to work.

The frontend stores campus tokens, Google credentials and challenges in memory.
JSON callback requests carry a separate random challenge secret; the signed
nonce is bound to that challenge, its intent, and the campus user for linking.
Successful attempts consume the challenge atomically with linking/session
issuance. Public endpoints are rate limited. Google keys come only from the
fixed HTTPS Google JWKS endpoint and use LavaLust's cache with bounded lifetimes
and refreshes for key rotation. Provider failures never bypass verification.
On supported Windows cURL builds, the fetch uses the native Windows CA store;
other environments must have a valid cURL CA bundle configured. Peer and hostname
verification remain enabled. See [cURL's certificate guidance](https://curl.se/docs/sslcerts.html).

## Checks and remaining manual verification

The routes are `GET /api/auth/google/config` (public client ID/enabled flag),
`POST /api/auth/google/challenge` (`intent: login` or authenticated `link`),
`POST /api/auth/google/login` (`credential`, `challenge_token`),
`GET /api/auth/google/link` (own link status), `POST /api/auth/google/link`
(`credential`, `challenge_token`, `password`), and `POST /api/auth/google/unlink`
(`password`). Link/status/unlink require the existing campus bearer token.

```powershell
php tests/google_identity_test.php
$env:CFABS_RUN_DB_TESTS='1'
php tests/google_account_test.php
$env:CFABS_TEST_URL='http://127.0.0.1:3000'
php tests/google_http_test.php
php tests/auth_http_test.php
```

On Wamp, key-generation tests may require `OPENSSL_CONF` set to PHP's
`extras/ssl/openssl.cnf`. Tests use generated RSA keys and process-local test
providers, never replace the real Google key cache. Database tests remove their
exact random account/challenge fixtures. Controller tests exercise the real
database/models and use a test session issuer; the authentication HTTP suite
checks the existing campus token issuer separately.

Live account linking and subsequent Google Sign-In were confirmed by the user
on 2026-11-07 after configuring local authorized origins. Repeat the live check
when OAuth client settings or origins change; automated local-key checks do not
establish that Google Cloud configuration is complete. Production setup remains deferred.
