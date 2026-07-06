<?php

namespace App\Support\Recommendation;

class SettingsComparator
{
    /**
     * Compare a user's current settings against the recommended settings.
     *
     * Iterates the recommended keys so the recommendation defines the vocabulary
     * and output order. Pasted keys absent from the recommendation are ignored;
     * recommended keys the user did not paste are skipped.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $recommended
     * @return list<array{setting: string, current: string, recommended: string, label: string}>
     */
    public static function compare(array $current, array $recommended): array
    {
        $diff = [];

        foreach ($recommended as $setting => $recommendedValue) {
            if (! array_key_exists($setting, $current)) {
                continue;
            }

            $currentDisplay = self::display($current[$setting]);
            $recommendedDisplay = self::display($recommendedValue);

            if (mb_strtolower($currentDisplay) === mb_strtolower($recommendedDisplay)) {
                continue;
            }

            $diff[] = [
                'setting' => (string) $setting,
                'current' => $currentDisplay,
                'recommended' => $recommendedDisplay,
                'label' => "{$currentDisplay} → {$recommendedDisplay}",
            ];
        }

        return $diff;
    }

    private static function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return trim((string) $value);
    }
}
