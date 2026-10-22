# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the module uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Added

- The `rumvision` skill for Mago Assistant: read-only access to RUMvision's Client API with one API key per project
  (`list_domains`, `describe_domain`, `get_metrics`, `get_ux_scores`, `get_ux_timeline`, `get_health_checks`,
  `get_alerts`, `list_annotations`).
- Configuration under Stores > Configuration > Mago Assistant > RUMvision with its own ACL resource, an encrypted API
  key that stays out of `app:config:dump`, and a domain per website.
- CI through check-extension (Mage-OS, Magento Open Source 2.4.8 and 2.4.9), `composer audit` and Dependabot.
