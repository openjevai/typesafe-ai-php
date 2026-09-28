# OpenJEV support

This fork of [sanmai/typesafe-ai-php](https://github.com/sanmai/typesafe-ai-php) adds
optional [OpenJEV](https://openjev.sh) support alongside the original TypeSafe AI
backend. OpenJEV is a free community gateway to the same Jev model. TypeSafe stays
the default; anyone with a TypeSafe key sees zero behaviour change.

## What was added

- `src/TypeSafeClient.php` — OpenJEV constants (`OPENJEV_BASE_URI`,
  `OPENJEV_API_KEY_ENV`, `OPENJEV_BASE_URL_ENV`, `PROVIDER_ENV`,
  `PROVIDER_TYPESAFE`, `PROVIDER_OPENJEV`), a `resolveProvider()` helper inside
  `createInstance()`, a `provider` property with a `provider()` getter, and a
  `defaultModel()` method returning the right model id for the active provider.
- `src/SystemOneRequest.php` — `MODEL_OPENJEV = 'openjev'` constant.
- `README.md` — short note after the intro and an "OpenJEV provider" subsection
  under "Building a Client".

No TypeSafe code path was renamed, removed, or re-defaulted.

## Provider selection rule

`createInstance()` resolves the provider at construction time:

1. `JEV_PROVIDER=openjev` (explicit choice) → OpenJEV.
2. `TYPESAFE_API_KEY` is set → TypeSafe (unchanged default).
3. Only `OPENJEV_API_KEY` is set → OpenJEV.
4. Otherwise → TypeSafe (which throws `InvalidArgumentException` if no key is
   configured, exactly as before).

When OpenJEV is selected:

- Endpoint: `https://api.openjev.sh` (overridable via `OPENJEV_BASE_URL`).
- Key: `OPENJEV_API_KEY`.
- Model: `openjev` (`SystemOneRequest::MODEL_OPENJEV`). `evaluate()` uses it
  automatically via `defaultModel()`.

TypeSafe-direct extras (503 overload status, `usage.cost`, `id`, `provider`
response fields) are handled by the same Guzzle retry middleware that already
covers `429` and every `5xx` response.

## Configuration

```bash
# Use OpenJEV with only an OpenJEV key:
OPENJEV_API_KEY=your-openjev-key php examples/urgency.php

# Force OpenJEV even when a TypeSafe key is also present:
JEV_PROVIDER=openjev php examples/urgency.php
```

## Verification

A live `POST https://api.openjev.sh/v1/systemone` request was sent with model
`openjev`, state `ping`, and one noul question. It returned HTTP 200 with a valid
`noul` answer. No repo code was executed. A re-grep confirmed that no hardcoded
`api.typesafe.ai` default was introduced by the changes.

## Upstream

Original project: https://github.com/sanmai/typesafe-ai-php by @sanmai
(Licensed under Apache-2.0; LICENSE and credits untouched).
