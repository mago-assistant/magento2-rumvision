# Mago Assistant: RUMvision

Add-on for [Mago Assistant](https://github.com/mago-assistant/mago) that lets the assistant answer questions about the
storefront's real-user performance from [RUMvision](https://www.rumvision.com/): Core Web Vitals per period, template,
device or other dimension, UX scores, health checks, alerts and deploy annotations. It uses the RUMvision Client API with
**one API key per project**, so every admin with the skill grant can use it without a RUMvision account.

Want to use your own RUMvision account instead (richer MCP tools, no project key)? See the RUMvision preset in
[magento2-mcp](https://github.com/mago-assistant/magento2-mcp): for admins who connect it there, this skill is hidden
("Replaces Skill = rumvision").

## Installation

```bash
composer require mago-assistant/magento2-rumvision
bin/magento module:enable MagoAssistant_Rumvision
bin/magento setup:upgrade
```

## Configuration

**Stores > Configuration > Mago Assistant > RUMvision** (ACL: *Stores > Configuration > Mago Assistant RUMvision*)

| Field | |
|---|---|
| API Key | Create it in RUMvision under *API keys* with the role **All** (data, health checks and alerts need it), limited to this project's domain and preferably to the server's IP |
| RUMvision Domain | Chosen from the domains the saved key can read; *Automatic* picks the one matching the store URL. RUMvision measures per tracking snippet, not per URL, so one domain usually covers every store view and language that loads its snippet, also on other hostnames. Only set it per website when that website has its own snippet |
| Timeout | Seconds per request |

The skill is only offered to the assistant when it is enabled and has an API key.

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

## Tests

From the Magento root:

```bash
vendor/bin/phpunit -c vendor/mago-assistant/magento2-rumvision/phpunit.xml.dist --bootstrap vendor/autoload.php
```
