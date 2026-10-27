<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/typo3-poppy

The [Personal Agent Protocol](https://personalagentprotocol.org/docs/0.2/spec)
(Poppy, draft 0.2) for TYPO3 v14. A person's own AI agent finds a site through
`/.well-known/poppy.json`, starts a Session, browses the site in it, and —
where the site has website users — asks the person to sign in and approve on
the site's own page.

Built on [koh/poppy](https://github.com/ohartwig/poppy), which does the
protocol. This extension provides the TYPO3 side: middlewares, the pages a
website user sees, storage in the TYPO3 database, a site set, and a backend
module with agent feedback.

## What a site gets

| Poppy 0.2 | Here |
|---|---|
| Discovery (3) | `/.well-known/poppy.json`, issuer metadata at `/.well-known/oauth-authorization-server/poppy` with `poppy_domains` |
| Sessions (4.2) | `POST /poppy/token` — signed-out Sessions for any Personal Agent |
| Web browsing (5) | `POST /poppy/browser-session`; the agent's browser is the Session's website user, signed in and out with it |
| Direct Sign-In (4.5) | `/poppy/authorize` — the site's felogin page, then a consent page (opt-in) |
| Device Sign-In (4.6) | `/poppy/device` — the user enters the agent's code (opt-in) |
| Sign-out (4.9) | `POST /poppy/revoke`, and `/poppy/account/agents` for the user |
| Feedback (8) | `POST /poppy/feedback`, listed in *Site Management > Agent feedback* |

**One issuer per site** — `https://{site host}/poppy` — because an
installation often hosts sites of different owners, and an issuer must never
stand for more than one of them. A site needs an HTTPS base on a host of its
own (no path, no port); any other site declares the website alone.

**Signed out by default.** Without a login page a site offers signed-out
Sessions: the agent is a known visitor, nothing more. That is a complete
deployment. With a felogin page and sign-in scopes, an agent can act as a
website user within what the user approved — `poppy:read` to see what the
user sees, `poppy:write` to change things. With `poppy:read` alone, the
agent's browser gets 403 on everything but GET and HEAD.

Not offered, and therefore not declared: Mediated Sign-In (the agent sends the
user's password), conversations with a Company Agent, and APIs — other
extensions can accept Session Tokens on their own endpoints through
`Koh\Poppy\Typo3\Poppy\CompanyResolver` and koh/poppy's `ResourceGuard`.

## Install

```bash
composer require koh/typo3-poppy
vendor/bin/typo3 extension:setup
```

Add the set to a site — that, not installing the extension, is what makes the
site speak Poppy:

```yaml
# config/sites/<site>/config.yaml
dependencies:
  - koh/poppy
```

Purge expired records daily, from the scheduler (*Execute console commands*)
or cron:

```bash
vendor/bin/typo3 koh:poppy:purge
```

The extension needs `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`: the
browser cookie and the DPoP nonces are derived from it.

## Site settings

| Setting | Default | |
|---|---|---|
| `poppy.enabled` | `true` | publish poppy.json |
| `poppy.server` | `true` | run the authorization server; off: `web: {}` only |
| `poppy.organizationName` | website title | the name agents show their users |
| `poppy.browserSession` | `true` | let an agent's browser join its Session |
| `poppy.feedback` | `true` | accept feedback |
| `poppy.loginPage` | `0` | uid of a page with felogin; 0: no sign-in |
| `poppy.signIn.directScopes` | empty | e.g. `poppy:read, poppy:write`; needs a login page |
| `poppy.signIn.deviceScopes` | empty | e.g. `poppy:read`; needs a login page |
| `poppy.sessionTokenTtl` | `3600` | seconds |
| `poppy.accountTokenTtl` | `7776000` | seconds; 0: until the user disconnects |
| `poppy.clients.allow` / `.block` | empty | client_id URLs or hosts |
| `poppy.dpopNonces` | `false` | require server-issued DPoP nonces |
| `poppy.templateRootPath` | empty | your own Fluid templates for the pages |

The user returns from the login page through `redirect_url`, so felogin needs
`plugin.tx_felogin_login.settings.redirectMode = getpost`.

## Security notes

- The machine endpoints run before TYPO3 looks up a frontend session and set
  no TYPO3 cookie.
- The agent's browser is signed in for the request only; no TYPO3 session is
  created, so nothing outlives the Poppy Session, and a sign-out takes effect
  on the next request. While the browser carries the cookie, any other login
  it holds is ignored.
- Consent pages cannot be framed and are never cached; every decision is a
  POST with a form token. Opening a device link approves nothing.
- The authorization request waits in the TYPO3 session while the user signs
  in; the redirect URI never travels through the browser.
- Agent metadata is fetched through TYPO3's HTTP client with redirects off,
  short timeouts and the address pinned (`CURLOPT_RESOLVE`) after a check that
  it is public. Through a configured proxy the proxy resolves and only the
  pre-flight check applies.

## Licence

MIT. See [LICENSE](LICENSE).
