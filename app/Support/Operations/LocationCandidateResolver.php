<?php

namespace App\Support\Operations;

/** Public display proposals only: no model, network, persistence or regional policy. */
final class LocationCandidateResolver
{
    private static array $shards = [];

    /** Only immutable names found in the public index; never unmatched/user text. */
    private static array $publicNames = [];

    private static ?array $manifest = null;

    private static ?array $hints = null;

    /** @return array{point?: array, estimated?: bool, candidate_count?: int, source?: string, blocked?: bool}|null */
    public static function resolve(string $name, array $context = []): ?array
    {
        $key = self::normalize($name);
        $rows = self::lookup($key);
        $rail = preg_match('/\b(?:rbf|rangierbahnhof|gbf|gueterbahnhof|hauptgueterbahnhof|bf|bhf|hbf|bahnhof|hauptbahnhof|haltepunkt)\b/u', $key) === 1;
        if ($rows === [] && $rail) {
            $stem = trim(preg_replace('/\b(?:rbf|rangierbahnhof|gbf|gueterbahnhof|hauptgueterbahnhof|bf|bhf|hbf|bahnhof|hauptbahnhof|haltepunkt)\b/u', '', $key) ?? '');
            $rows = self::lookup(preg_replace('/\s+/', ' ', $stem) ?? $stem);
        }
        if ($rows === []) {
            return null;
        }
        $allCount = count($rows);
        $city = self::normalize($context['city'] ?? '');
        $postalCities = array_map(self::normalize(...), $context['postal_cities'] ?? []);
        $postalPoints = $context['postal_points'] ?? [];
        $anchor = $context['anchor'] ?? null;
        if ($city !== '' || $postalCities !== []) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($city === '' || self::matchesCity($row, $city))
                && ($postalCities === [] || count(array_filter($postalCities, fn (string $postal): bool => self::matchesCity($row, $postal))) > 0)
                && ($postalPoints === [] || count(array_filter($postalPoints, fn (array $postal): bool => self::distance($row[2], $row[3], $postal[1], $postal[2]) <= 30)) > 0)
                && ($anchor === null || self::distance($row[2], $row[3], $anchor[0], $anchor[1]) <= 30)));
            if ($rows === []) {
                return ['blocked' => true, 'reason' => 'structured_context'];
            }
        }

        $texts = $context['texts'] ?? [];
        $normalizedTexts = [];
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 5000) {
                // Malformed descriptive text supplies no geographic evidence.
                $normalizedTexts = [];
                break;
            }
            $normalizedTexts[] = self::normalize(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $text = implode(' ', $normalizedTexts);
        $contextual = [];
        $negated = [];
        foreach ($rows as $row) {
            $full = self::normalize($row[1]);
            $mentions = self::manifest()['municipalities'][$row[6]]['names'] ?? [];
            if ($full !== $key && mb_strlen($full) > mb_strlen($key)) {
                $mentions[] = $full;
            }
            foreach ($mentions as $mention) {
                if ($mention !== $key && self::contains($text, $mention)) {
                    if (self::negatedMention($text, $mention)) {
                        $negated[$row[0]] = true;
                    } else {
                        $contextual[$row[0]] = true;
                    }
                }
            }
        }
        $rows = array_values(array_filter($rows, fn (array $row): bool => ! isset($negated[$row[0]])));
        if ($rows === [] || self::negatedMention($text, $key)) {
            return ['blocked' => true, 'reason' => 'service_context'];
        }
        $contextCodes = array_unique(array_map(fn (array $row): string => $row[6] ?: (string) $row[0], array_filter($rows, fn (array $row): bool => isset($contextual[$row[0]]))));
        if (count($contextCodes) > 1) {
            return ['blocked' => true, 'reason' => 'service_context'];
        }
        $weakRail = preg_match('/\b(?:wgm|rb|tf|azf|wagenmeister|wagenpruefer|rangierbegleiter|rangierdienst|lokfuehrer|triebfahrzeugfuehrer)\b/u', $text) === 1;
        $yard = preg_match('/\b(?:rbf|rangierbahnhof|gbf|gueterbahnhof|hauptgueterbahnhof)\b/u', $key) === 1;
        usort($rows, function (array $a, array $b) use ($contextual, $rail, $weakRail, $yard): int {
            $rank = static function (array $row) use ($contextual, $rail, $weakRail, $yard): array {
                $isRail = in_array($row[4], ['RSTN', 'RSTP', 'RYD'], true);
                $railRank = $yard && $row[4] === 'RYD' ? 0 : ($isRail ? 1 : 2);
                $administrative = match ($row[4]) {
                    'PPLC' => 0, 'PPLA' => 1, 'PPLA2' => 2, 'PPLA3' => 3, 'PPLA4' => 4, 'PPLA5' => 5, default => 6,
                };

                // Explicit station/yard wording may select entity kind first.
                // Generic WGM/RB work descriptions never outrank a primary name
                // with a distant station's shortened alias.
                return [isset($contextual[$row[0]]) ? 0 : 1, $rail ? $railRank : 0, $row[7],
                    $weakRail ? $railRank : ($isRail ? 1 : 0), $administrative, -$row[5], $row[0]];
            };

            return $rank($a) <=> $rank($b);
        });
        $selected = $rows[0];
        $geographies = array_unique(array_map(fn (array $row): string => $row[6] ?: (string) $row[0], $rows));
        $estimated = count($geographies) > 1;
        if ($estimated && self::contradictoryText($text)) {
            return ['blocked' => true, 'reason' => 'service_context'];
        }
        $source = $postalCities !== [] ? 'postcode_context' : ($city !== '' ? 'city_context' : (isset($contextual[$selected[0]]) ? 'service_context' : ($rail || $weakRail ? 'rail_context' : 'local_candidates')));

        $municipality = self::manifest()['municipalities'][$selected[6]]['name'] ?? '';
        $displayName = $selected[1];
        if ($municipality !== '' && ! self::contains(self::normalize($displayName), self::normalize($municipality))) {
            $displayName .= ' ('.$municipality.')';
        }

        return ['point' => $selected, 'display_name' => $displayName, 'estimated' => $estimated, 'candidate_count' => $allCount, 'source' => $source];
    }

    public static function normalize(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $value = preg_replace('/\bu\.\s+/', 'unter ', $value) ?? $value;
        $value = trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');

        return trim(preg_replace([
            '/\b(?:oberpf|opf)\b/', '/\bunterfr\b/', '/\boberfr\b/', '/\bweinstr\b/', '/\bi d\b/', '/\ba d\b/',
        ], ['oberpfalz', 'unterfranken', 'oberfranken', 'weinstrasse', 'in der', 'an der'], $value) ?? $value);
    }

    private static function matchesCity(array $row, string $city): bool
    {
        $municipality = self::manifest()['municipalities'][$row[6]] ?? null;

        return self::normalize($row[1]) === $city || ($municipality !== null && in_array($city, $municipality['names'], true));
    }

    private static function distance(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        $a = sin(deg2rad($otherLatitude - $latitude) / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($otherLatitude)) * sin(deg2rad($otherLongitude - $longitude) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private static function negatedMention(string $text, string $mention): bool
    {
        return preg_match('/\b(?:nicht|kein|keine|keinen|ohne|statt|anstatt|ausgenommen)(?:\s+(?!(?:sondern|aber)\b)[a-z0-9]+){0,3}\s+'.preg_quote($mention, '/').'\b/u', $text) === 1;
    }

    private static function contradictoryText(string $text): bool
    {
        if (self::$hints === null) {
            $details = json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-location-details.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$hints = array_fill_keys(array_map(self::normalize(...), array_keys($details['foreign_city_mentions'])), true);
        }
        $words = explode(' ', $text);
        for ($offset = 0, $count = count($words); $offset < $count; $offset++) {
            for ($length = min(8, $count - $offset); $length > 0; $length--) {
                $part = implode(' ', array_slice($words, $offset, $length));
                if (isset(self::$hints[$part]) && ! self::negatedMention($text, $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return str_contains(' '.$haystack.' ', ' '.$needle.' ');
    }

    private static function lookup(string $key): array
    {
        if ($key === '') {
            return [];
        }
        if (isset(self::$publicNames[$key])) {
            return self::$publicNames[$key];
        }
        $shard = sprintf('%02x', hexdec(substr(hash('sha256', $key), 0, 2)) % 64);
        if (! array_key_exists($shard, self::$shards)) {
            if (count(self::$shards) >= 8) {
                unset(self::$shards[array_key_first(self::$shards)]);
            }
            self::$shards[$shard] = json_decode(file_get_contents(self::directory().'/'.$shard.'.json'), true, 512, JSON_THROW_ON_ERROR);
        }

        $rows = self::$shards[$shard][$key] ?? [];
        if ($rows !== []) {
            if (count(self::$publicNames) >= 256) {
                unset(self::$publicNames[array_key_first(self::$publicNames)]);
            }
            self::$publicNames[$key] = $rows;
        }

        return $rows;
    }

    private static function manifest(): array
    {
        return self::$manifest ??= json_decode(file_get_contents(self::directory().'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 3).'/resources/data/maps/germany-location-candidates';
    }
}
