<?php

namespace App\Services\DeviceManagement;

use RuntimeException;

final class ConnectorNetworkGuard
{
    /** @var list<string> */
    private const BLOCKED_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const BLOCKED_IPV6_RANGES = [
        '::/96',
        '::1/128',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    public function __construct(private readonly ConnectorDnsResolver $dns) {}

    /**
     * Resolve and pin a public HTTPS connector before a bearer token is sent.
     * Port mode never performs DNS and remains exactly on local loopback.
     *
     * @return array<string, mixed>
     */
    public function requestOptions(string $url, string $mode): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Das Connector-Ziel ist ungültig.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $options = $this->directConnectionOptions();

        if ($mode === 'port') {
            if ($scheme !== 'http' || $host !== '127.0.0.1' || $port < 1 || $port > 65535) {
                throw new RuntimeException('Der lokale Connector ist nicht exakt an 127.0.0.1 gebunden.');
            }

            return $options;
        }

        if ($mode !== 'subdomain'
            || $scheme !== 'https'
            || $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException('Das öffentliche Connector-Ziel ist nicht zulässig.');
        }

        $addresses = $this->dns->addressesFor($host);
        if ($addresses === [] || count($addresses) > 32) {
            throw new RuntimeException('Das öffentliche Connector-Ziel konnte nicht sicher aufgelöst werden.');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new RuntimeException('Das öffentliche Connector-Ziel löst auf eine nicht zulässige Adresse auf.');
            }
        }

        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Sicheres DNS-Pinning für Connectoren ist auf diesem Server nicht verfügbar.');
        }

        sort($addresses, SORT_STRING);
        $selected = $addresses[0];
        $pinnedAddress = str_contains($selected, ':') ? '['.$selected.']' : $selected;
        $options['curl'][(int) constant('CURLOPT_RESOLVE')] = ["{$host}:{$port}:{$pinnedAddress}"];

        return $options;
    }

    /** @return array<string, mixed> */
    private function directConnectionOptions(): array
    {
        $options = [
            'proxy' => [
                'http' => '',
                'https' => '',
            ],
            'curl' => [],
        ];

        if (defined('CURLOPT_PROXY')) {
            $options['curl'][(int) constant('CURLOPT_PROXY')] = '';
        }
        if (defined('CURLOPT_NOPROXY')) {
            $options['curl'][(int) constant('CURLOPT_NOPROXY')] = '*';
        }

        return $options;
    }

    private function isPublicAddress(string $address): bool
    {
        $address = trim($address);
        if ($address === '' || str_contains($address, '%')) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                return false;
            }

            return ! $this->isInAnyRange($address, self::BLOCKED_IPV4_RANGES);
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $packed = inet_pton($address);
        if (! is_string($packed) || strlen($packed) !== 16) {
            return false;
        }

        // IPv4-mapped IPv6 must be evaluated using the stricter IPv4 rules.
        if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $mapped = inet_ntop(substr($packed, 12));

            return is_string($mapped) && $this->isPublicAddress($mapped);
        }

        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        return ! $this->isInAnyRange($address, self::BLOCKED_IPV6_RANGES);
    }

    /** @param list<string> $ranges */
    private function isInAnyRange(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->isInRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private function isInRange(string $address, string $range): bool
    {
        [$network, $prefix] = explode('/', $range, 2);
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);
        if (! is_string($addressBytes)
            || ! is_string($networkBytes)
            || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        if ($fullBytes > 0 && substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
