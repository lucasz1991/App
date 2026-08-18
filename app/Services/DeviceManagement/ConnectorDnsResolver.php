<?php

namespace App\Services\DeviceManagement;

class ConnectorDnsResolver
{
    /** @return list<string> */
    public function addressesFor(string $host): array
    {
        return $this->resolve(strtolower(rtrim(trim($host), '.')), 0, []);
    }

    /**
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function resolve(string $host, int $depth, array $seen): array
    {
        if ($host === '' || $depth > 5 || isset($seen[$host]) || ! function_exists('dns_get_record')) {
            return [];
        }
        $seen[$host] = true;

        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        if (! is_array($records) || count($records) > 128) {
            return [];
        }

        $addresses = [];
        $aliases = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            if (is_string($record['ip'] ?? null)) {
                $addresses[] = trim($record['ip']);
            }
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = trim($record['ipv6']);
            }
            if (($record['type'] ?? null) === 'CNAME' && is_string($record['target'] ?? null)) {
                $aliases[] = strtolower(rtrim(trim($record['target']), '.'));
            }
        }

        foreach (array_values(array_unique($aliases)) as $alias) {
            $addresses = array_merge($addresses, $this->resolve($alias, $depth + 1, $seen));
        }

        return array_values(array_unique(array_filter(
            $addresses,
            static fn (mixed $address): bool => is_string($address) && $address !== '',
        )));
    }
}
