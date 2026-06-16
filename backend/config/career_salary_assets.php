<?php

declare(strict_types=1);

$resolvePreviewSlugs = static function (): array {
    $raw = trim((string) env('CAREER_SALARY_ASSETS_PREVIEW_SLUGS', ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_unique(array_map(
                static fn (mixed $slug): string => strtolower(trim((string) $slug)),
                $decoded
            )));
        }

        return array_values(array_unique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []
        )));
    }

    return [
        'accountants-and-auditors',
        'actuaries',
        'computer-programmers',
        'agents-and-business-managers-of-artists-performers-and-athletes',
        'writers-and-authors',
        'zoologists-and-wildlife-biologists',
        'wind-turbine-technicians',
        'woodworking-machine-setters-operators-and-tenders-except-sawing',
        'air-traffic-controllers',
        'athletes-and-sports-competitors',
        'command-and-control-center-officers',
        'command-and-control-center-specialists',
        'aircraft-launch-and-recovery-officers',
        'artillery-and-missile-officers',
        'armored-assault-vehicle-officers',
        'airline-and-commercial-pilots',
        'airline-pilots-copilots-and-flight-engineers',
        'commercial-pilots',
        'air-crew-officers',
        'special-forces-officers',
        'acute-care-nurses',
        'advanced-practice-psychiatric-nurses',
        'family-medicine-physicians',
        'general-internal-medicine-physicians',
        'registered-nurses',
        'critical-care-nurses',
        'nurse-anesthetists',
        'nurse-practitioners',
        'nurse-midwives',
        'acupuncturists',
        'elementary-school-teachers-except-special-education',
        'high-school-teachers',
        'engineering-teachers-postsecondary',
        'health-specialties-teachers-postsecondary',
        'career-and-technical-education-teachers',
        'data-scientists',
        'database-administrators',
        'web-developers',
        'web-and-digital-interface-designers',
        'computer-and-information-systems-managers',
        'art-directors',
        'craft-artists',
        'poets-lyricists-and-creative-writers',
        'fine-artists-including-painters-sculptors-and-illustrators',
        'choreographers',
        'animal-scientists',
        'environmental-science-and-protection-technicians',
        'environmental-engineers',
        'top-executives',
        'carpenters',
    ];
};

return [
    'staging_preview_enabled' => env('CAREER_SALARY_ASSETS_STAGING_PREVIEW_ENABLED', false),
    'preview_slugs' => $resolvePreviewSlugs(),
];
