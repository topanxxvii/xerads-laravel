# Contract 2 fixtures

These files pin contract 2 exactly as XerAds speaks it: the messages and
documents it sends, and the signature vectors both sides compute. They are
kept byte-identical with XerAds' own contract tests, so both sides assert the
same contract. Please do not edit them: if one looks wrong, open an issue.

- `signature-v2-vectors.json`: push and pull signature vectors for contract 2.
- `article-upsert.json`: a complete `article.upsert` envelope.
- `widget-embeds.json`: every embed format XerAds generates for one widget.
- `site-settings.json`: the settings document XerAds serves a new site named
  "Toko Rumah" at `http://localhost` (defaults, version 1).

`../v1/custom-payload.json` follows the same rule: the exact body, timestamp,
secret and signature XerAds sends to a custom endpoint under the frozen
contract 1, kept byte-identical with XerAds' own tests. Do not edit it either.
