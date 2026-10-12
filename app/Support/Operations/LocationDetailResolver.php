<?php

namespace App\Support\Operations;

/** Bounded display-only recognition against immutable public locality data. */
final class LocationDetailResolver
{
    private static ?array $data = null;

    /**
     * @return array{point: array, source: string}|null
     */
    public static function resolve(array $base, array $locations, array $texts, bool $missingLocation): ?array
    {
        if (! in_array($base['state'], ['located', 'unknown'], true)) {
            return null;
        }

        $data = self::data();
        $city = $data['cities'][self::normalize($base['place'] ?? '')] ?? null;
        if ($city === null && ! $missingLocation) {
            return null;
        }
        $aliases = $city !== null ? ($data['aliases'][$city] ?? []) : $data['qualified'];

        // Explicit, exact location text outranks descriptive mentions. A supplied
        // foreign/contradictory city must not become an invitation to guess.
        foreach ($locations as $location) {
            $name = self::normalize($location);
            if (self::hasOtherCity($name, $city, $data)) {
                return null;
            }
            if (isset($aliases[$name])) {
                return ['point' => $data['points'][$aliases[$name]], 'source' => 'location'];
            }
            $qualified = preg_replace('/^(?:bahnhof|haltepunkt|bf|bhf) | (?:bahnhof|haltepunkt|bf|bhf)$/u', '', $name) ?? $name;
            if (isset($aliases[$qualified])) {
                return ['point' => $data['points'][$aliases[$qualified]], 'source' => 'location'];
            }
        }

        $matches = [];
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            // Never truncate invalid/oversized descriptions into a seemingly
            // unambiguous prefix, and never retain user text in the static cache.
            if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 5000) {
                return null;
            }
            $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $text) ?? '';
            $name = self::normalize(html_entity_decode(preg_replace('/<[^>]*>/u', ' ', $text) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // Free-form negations, alternatives and routes need human context.
            // Conservatively keep the known city rather than selecting one point.
            if (preg_match('/\b(?:nicht|kein(?:e|en|em|er|es)?|ohne|statt|anstatt|alternativ|oder|gegebenenfalls|ggf|ausgenommen|außer|ausser)\b/u', $name)
                || preg_match('/\bvon\b.*\b(?:nach|bis)\b/u', $name)
                || self::hasOtherCity($name, $city, $data)) {
                return null;
            }
            foreach (self::matchingNames($name, $aliases) as $id) {
                if ($id === null) {
                    return null;
                }
                $matches[$id] = true;
            }
        }
        if (count($matches) !== 1) {
            return null;
        }
        $point = $data['points'][array_key_first($matches)];
        if ($city === null) {
            // A previously missing location requires a full city-qualified name;
            // any other named city still blocks that interpretation.
            foreach ($texts as $text) {
                if (self::hasOtherCity(self::normalize(strip_tags($text ?? '')), $point[5], $data)) {
                    return null;
                }
            }
        }

        return ['point' => $point, 'source' => 'service_text'];
    }

    private static function hasOtherCity(string $name, ?int $city, array $data): bool
    {
        if (self::matchingNames($name, $data['foreign_city_mentions']) !== []) {
            return true;
        }
        if ($city === null) {
            return false;
        }
        foreach (self::matchingNames($name, $data['city_mentions']) as $id) {
            if ($id === null || $id !== $city) {
                return true;
            }
        }

        return false;
    }

    /** At most eight-word exact dictionary lookups; no fuzzy or nearest match. */
    private static function matchingNames(string $name, array $index): array
    {
        $words = explode(' ', $name);
        $matches = [];
        for ($offset = 0, $count = count($words); $offset < $count; $offset++) {
            for ($length = min(8, $count - $offset); $length > 0; $length--) {
                $part = implode(' ', array_slice($words, $offset, $length));
                if (array_key_exists($part, $index)) {
                    $matches[] = $index[$part];
                    $offset += $length - 1;
                    break;
                }
            }
        }

        return $matches;
    }

    private static function normalize(mixed $value): string
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            ? trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value, 'UTF-8')) ?? '')
            : '';
    }

    private static function data(): array
    {
        return self::$data ??= json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-location-details.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
