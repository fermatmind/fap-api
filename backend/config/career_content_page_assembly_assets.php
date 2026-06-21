<?php

declare(strict_types=1);

$resolvePreviewSlugs = static function (): array {
    $raw = trim((string) env('CAREER_CONTENT_PAGE_ASSEMBLY_PREVIEW_SLUGS', ''));
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
        'acute-care-nurses',
        'emergency-medicine-physicians',
        'nurse-anesthetists',
        'pharmacists',
        'diagnostic-medical-sonographers',
        'medical-and-clinical-laboratory-technologists',
        'genetic-counselors',
        'physicians-and-surgeons',
        'airline-pilots-copilots-and-flight-engineers',
        'aviation-inspectors',
        'aircraft-and-avionics-equipment-mechanics-and-technicians',
        'commercial-pilots',
        'administrative-law-judges-adjudicators-and-hearing-officers',
        'judges-magistrate-judges-and-magistrates',
        'lawyers',
        'judicial-law-clerks',
        'military-careers',
        'first-line-supervisors-of-air-crew-members',
        'first-line-supervisors-of-weapons-specialists-crew-members',
        'command-and-control-center-officers',
        'elementary-school-teachers-except-special-education',
        'school-and-career-counselors',
        'adult-literacy-and-ged-teachers',
        'special-education-teachers-elementary-school',
        'actors',
        'poets-lyricists-and-creative-writers',
        'multimedia-artists-and-animators',
        'dancers-and-choreographers',
        'electricians',
        'plumbers-pipefitters-and-steamfitters',
        'firefighters',
        'police-and-sheriff-s-patrol-officers',
        'heavy-and-tractor-trailer-truck-drivers',
        'construction-and-building-inspectors',
        'web-developers',
        'data-scientists',
        'information-security-analysts',
        'civil-engineers',
        'biomedical-engineers',
        'computer-systems-engineers-architects',
    ];
};

return [
    'staging_preview_enabled' => env('CAREER_CONTENT_PAGE_ASSEMBLY_PREVIEW_ENABLED', false),
    'preview_slugs' => $resolvePreviewSlugs(),
];
