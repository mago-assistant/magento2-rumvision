# Mago Assistant: RUMvision

Add-on for [Mago Assistant](https://github.com/mago-assistant/mago) that lets the assistant answer questions about the
storefront's real-user performance from [RUMvision](https://www.rumvision.com/): Core Web Vitals per period, template,
device or other dimension, UX scores, health checks, alerts and deploy annotations. It uses the RUMvision Client API with
**one API key per project**, so every admin with the skill grant can use it without a RUMvision account.

Want to use your own RUMvision account instead (richer MCP tools, no project key)? See the RUMvision preset in
[magento2-mcp](https://github.com/mago-assistant/magento2-mcp): for admins who connect it there, this skill is hidden
("Replaces Skill = rumvision").

## Compatibility

| | |
|---|---|
| Magento Open Source | 2.4.8, 2.4.9 |
| Mage-OS | 3.x |
| PHP | 8.3, 8.4, 8.5 |
| Mago Assistant | 1.x |
| Storefront | Not affected: the module only adds a skill to the admin assistant, so Luma, Hyvä and headless stores all work |

CI tests the currently supported Mage-OS release and Magento Open Source 2.4.8 and 2.4.9.

## Installation

```bash
composer require mago-assistant/magento2-rumvision
bin/magento module:enable MagoAssistant_Rumvision
bin/magento setup:upgrade
```

## Configuration

### Stores > Configuration > Mago Assistant > RUMvision > RUMvision

Access to this section is its own ACL resource: *Stores > Settings > Configuration > Mago Assistant RUMvision*.

| Setting | Default | Scope | What it does |
|---------|---------|-------|--------------|
| Enabled | No | Default | Turns the skill on. While off, the assistant does not see the skill and every call to it is refused, even with a saved key. |
| API Key | (empty) | Default | The RUMvision API key, stored encrypted and kept out of `app:config:dump`. Create it in RUMvision under *API keys* with the role **All** (data, health checks and alerts need it), limited to this project's domain and preferably to the server's IP. The skill stays hidden until a key is saved. |
| RUMvision Domain | Automatic | Default, Website | The RUMvision domain the assistant uses when the question does not name one. The list shows the domains the saved key can read. *Automatic* picks the domain matching each store view's base URL. RUMvision measures per tracking snippet, not per URL, so one domain usually covers every store view and language that loads its snippet, also on other hostnames: only set it per website when that website has its own snippet. |
| Timeout (seconds) | 20 | Default | Maximum time per RUMvision request. The domain catalog (`describe_domain`) always gets at least 90 seconds, because RUMvision needs that long for an organisation-wide key. |

The settings below *Enabled* only show once it is set to Yes.

## Getting started

1. In RUMvision, create an API key with the role **All** under *API keys*, limited to this project's domain.
2. Go to **Stores > Configuration > Mago Assistant > RUMvision**, set **Enabled** to *Yes*, paste the key in **API Key**
   and save.
3. Reload the page. **RUMvision Domain** now lists the domains the key can read. Leave it on *Automatic* when the store's
   base URL matches the RUMvision domain, otherwise pick the domain and save again.
4. Give admins access to the skill `rumvision` on Mago's Skills page, like any other skill.
5. Ask the assistant something like *"How did LCP on mobile develop over the last 4 weeks?"* or *"Which templates have
   the worst INP?"*.

![RUMvision configuration](docs/images/config-rumvision.png)
<!-- TODO screenshot: Stores > Configuration > Mago Assistant > RUMvision, Enabled = Yes, domain list filled -->

![The assistant answering a Core Web Vitals question](docs/images/assistant-rumvision.png)
<!-- TODO screenshot: the Mago chat answering a question with get_metrics (total plus per-hostname rows) -->

## What the assistant can do

The skill `rumvision` is read-only and has these actions:

| Action | RUMvision API |
|---|---|
| `list_domains` | `GET /domains`, plus which store views each domain measures |
| `describe_domain` | Metric, dimension and template slugs of one domain (from `GET /domains?includeMetrics=true`, trimmed and cached for a day: for an organisation-wide key RUMvision needs ~50s and 2 MB for that call) |
| `get_metrics` | `POST /data/distribution/{domain}[/{dimension}]` |
| `get_ux_scores` | `POST /data/ux-scores/{domain}[/{dimension}]` |
| `get_ux_timeline` | `POST /data/ux-scores-timeline/{domain}` |
| `get_health_checks` | `GET /health-checks/{domain}` |
| `get_alerts` | `GET /alerts/{domain}` |
| `list_annotations` | `GET /annotations` |

- `domain` defaults to the configured domain, so the assistant does not guess hostnames. The skill's instructions list
  which hostnames and store views that domain measures (from the store base URLs); for one shop the assistant breaks the
  numbers down by the `hostname` dimension. Store views that differ only by path (e.g. `/en`) cannot be filtered through
  the API.
- Shared parameters (`domain`, `periods`, `metrics`, `dimension`, `device`, `percentiles`) have one shape in every action;
  `periods` also accepts a single `{from, to}`, a list of ranges or `"7d"`, and defaults to the last 28 days.
- The skill's instructions tell the assistant to judge by sample counts and by each metric's own thresholds.
- Output is kept within Mago's per-result token cap: health checks return their open findings (with `include_closed`
  also the history) and the remediation step titles; annotations return the newest 20 (max 50) with short descriptions.
- `filters.device-type` is always sent (both devices when no `device` is given): the API rejects the data endpoints
  without it.

## Troubleshooting

**The assistant does not offer the skill.** Check that *Enabled* is Yes and an API key is saved, and that the admin has
the `rumvision` grant on Mago's Skills page. An admin who connected RUMvision through magento2-mcp with "Replaces Skill =
rumvision" uses that connection instead.

**The domain list only shows *Automatic*.** RUMvision rejected the key or did not answer. The reason is logged to
`var/log/system.log` as *RUMvision domains could not be loaded: ...*. The list is cached for an hour, a failure for five
minutes.

**"Domain not found, or the API key has no access to it".** RUMvision answers 404 both for an unknown domain and for an
endpoint the key's role does not cover. Give the key the **All** role.

**"RUMvision rate limit reached".** The answer says when to try again when RUMvision sends a `Retry-After`.

**`describe_domain` is slow or fails.** The first call per day fetches the full catalog, which can take close to a
minute. A failed fetch is remembered for five minutes so the assistant does not keep waiting on it; a domain added to
the key later is picked up on its first `describe_domain`.

## Tests

Unit tests run standalone from the module:

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

The integration tests in `Test/Integration` need Magento's integration test framework (`dev/tests/integration` in a
Magento project); CI runs them through [check-extension](.github/workflows/check-extension.yaml).

## More

- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)
- License: [MIT](LICENSE)
