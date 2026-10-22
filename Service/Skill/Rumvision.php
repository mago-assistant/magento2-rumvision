<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use MagoAssistant\Rumvision\Service\DomainResolver;

/**
 * The name "rumvision" is what the MCP add-on's "Replaces Skill" refers to; keep it stable.
 */
class Rumvision extends AbstractSkill
{
    /**
     * @param AuthorizationInterface $authorization
     * @param DomainResolver $domainResolver
     * @param ActionInterface[] $actions
     */
    public function __construct(
        AuthorizationInterface $authorization,
        private readonly DomainResolver $domainResolver,
        array $actions = []
    ) {
        parent::__construct($authorization, $actions);
    }

    public function getName(): string
    {
        return 'rumvision';
    }

    /**
     * Reads only RUMvision's API, no Magento data; '' would be refused for everyone.
     *
     * @param array<string, mixed> $input
     * @return string
     */
    public function getMagentoAcl(array $input = []): string
    {
        return Acl::MAGO_PER_USER;
    }

    protected function getBaseDescription(): string
    {
        return 'Real-user performance of the storefront from RUMvision (RUM): Core Web Vitals / CWV (LCP, INP, CLS, '
            . 'TTFB, FCP), page speed and site performance per period, template, hostname, device or other dimension, '
            . 'UX scores, health checks, alerts and deploy annotations. Use it for any question about how fast or '
            . 'stable the shop is for visitors.';
    }

    protected function getBaseInstructions(): string
    {
        return implode("\n", [
            ...$this->domainInstructions(),
            'Metric and dimension slugs differ per domain: call describe_domain before using a dimension other than '
                . 'hostname, template-type and device-type, or a metric other than lcp, inp, cls, ttfb and fcp.',
            'get_metrics returns per metric a "total" for the whole domain (and "by_<dimension>" rows when broken '
                . 'down): report the total unless asked about one hostname or segment, and never present a row as the '
                . 'domain. Give every value with its status (good, moderate or poor, from the "_status" field) and its '
                . 'sample count.',
            'Always look at the sample counts: a segment with few samples is a lead, not proof; say so, and prefer a '
                . 'broader grouping or a longer period over deeper segmentation.',
            'To compare, pass two named periods (e.g. "current" and "previous") in one call instead of two calls.',
            'Field data shows what is slow and where, not why: suggest checking the page itself to find the cause.',
        ]);
    }

    /**
     * @return string[]
     */
    private function domainInstructions(): array
    {
        $domains = $this->domainResolver->getDomains();
        if ($domains === []) {
            return ['No RUMvision domain is linked to this store: always pass "domain", taking the exact name from '
                . 'list_domains (e.g. "shop" means "shop.nl" when that is listed); never guess a hostname.'];
        }

        $lines = [];
        foreach ($domains as $domain => $storeViews) {
            $hosts = [];
            foreach ($storeViews as $view) {
                $hosts[$view['host']][] = $view['store'] . ($view['path'] !== '/' ? ' under ' . $view['path'] : '');
            }
            $parts = [];
            foreach ($hosts as $host => $views) {
                $parts[] = $host . ' (' . implode(', ', $views) . ')';
            }
            $lines[] = sprintf('RUMvision domain %s measures: %s.', $domain, implode('; ', $parts));
        }
        $lines[] = count($domains) === 1
            ? 'Omit "domain" for this domain; for any other, take the exact name from list_domains, never guess it.'
            : 'Pass the "domain" of the shop asked about; for any other, take the exact name from list_domains.';
        $lines[] = 'RUMvision measures per tracking snippet, not per URL, so a domain\'s totals mix all hostnames '
            . 'above. '
            . 'For one shop, break down by the dimension "hostname" and use that shop\'s row. Store views that differ '
            . 'only by path (e.g. /en) cannot be filtered; say so, or use the "domain-path" breakdown for top pages.';
        $lines[] = 'Hostnames not listed above (e.g. a staging or dev host) are not shops of this installation: leave '
            . 'them out of shop comparisons and mention that they pollute the totals.';

        return $lines;
    }
}
