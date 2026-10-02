# Contract 2 fixtures

These files are copies, not originals. Each one is defined in the XerAds
backend repository, under `tests/Fixtures/sites/`, where the backend's own test
suite pins the sending side against the same bytes. Change them there first,
then copy them here unchanged, so both suites keep asserting the same contract.

| File here | Original in the backend repository |
| --- | --- |
| `signature-v2-vectors.json` | `tests/Fixtures/sites/signature-v2-vectors.json` |
| `article-upsert.json` | `tests/Fixtures/sites/article-upsert.json` |
| `widget-embeds.json` | `tests/Fixtures/sites/widget-embeds.json` |
| `site-settings.json` | `tests/Fixtures/sites/site-settings.json` |

- `signature-v2-vectors.json`: push and pull signature vectors for contract 2.
- `article-upsert.json`: a complete `article.upsert` envelope.
- `widget-embeds.json`: every embed format XerAds generates for one widget.
- `site-settings.json`: the settings document XerAds serves a new site named
  "Toko Rumah" at `http://localhost` (defaults, version 1).

`../v1/custom-payload.json` comes from the same directory (`v1-custom-payload.json`): the exact body, timestamp, secret and signature XerAds sends to a custom endpoint under the frozen contract 1.
