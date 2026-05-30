<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class FunnelEventTaxonomy
{
    public const PAGE_VIEW = 'page_view';

    public const TEST_START = 'test_start';

    public const QUESTION_ANSWER = 'question_answer';

    public const TEST_SUBMIT = 'test_submit';

    public const RESULT_VIEW = 'result_view';

    public const CHECKOUT_START = 'checkout_start';

    public const ORDER_CREATED = 'order_created';

    public const PAYMENT_SUCCESS = 'payment_success';

    public const REPORT_UNLOCK = 'report_unlock';

    public const REPORT_READY = 'report_ready';

    public const PDF_DOWNLOAD = 'pdf_download';

    public const SHARE_GENERATE = 'share_generate';

    public const SHARE_CLICK = 'share_click';

    public const MEMBERSHIP_START = 'membership_start';

    public const RETEST_START = 'retest_start';

    public const HISTORICAL_REPORT_REVISIT = 'historical_report_revisit';

    public const SOURCE_ATTRIBUTION = 'source_attribution';

    /**
     * @var list<string>
     */
    public const CANONICAL_EVENTS = [
        self::PAGE_VIEW,
        self::TEST_START,
        self::QUESTION_ANSWER,
        self::TEST_SUBMIT,
        self::RESULT_VIEW,
        self::CHECKOUT_START,
        self::ORDER_CREATED,
        self::PAYMENT_SUCCESS,
        self::REPORT_UNLOCK,
        self::REPORT_READY,
        self::PDF_DOWNLOAD,
        self::SHARE_GENERATE,
        self::SHARE_CLICK,
        self::MEMBERSHIP_START,
        self::RETEST_START,
        self::HISTORICAL_REPORT_REVISIT,
        self::SOURCE_ATTRIBUTION,
    ];

    /**
     * @var array<string, string>
     */
    public const LEGACY_ALIAS_MAP = [
        'start_attempt' => self::TEST_START,
        'submit_attempt' => self::TEST_SUBMIT,
        'view_result' => self::RESULT_VIEW,
        'create_order' => self::ORDER_CREATED,
        'begin_checkout' => self::CHECKOUT_START,
        'payment_confirmed' => self::PAYMENT_SUCCESS,
        'purchase_success' => self::PAYMENT_SUCCESS,
        'pay_success' => self::PAYMENT_SUCCESS,
        'unlock_success' => self::REPORT_UNLOCK,
        'clinical_unlock_success' => self::REPORT_UNLOCK,
        'report_pdf_view' => self::PDF_DOWNLOAD,
        'revisit_result' => self::HISTORICAL_REPORT_REVISIT,
    ];

    /**
     * @var list<string>
     */
    public const FIRST_RESULT_OR_REPORT_VIEW_ALIASES = [
        self::RESULT_VIEW,
        'view_result',
        'report_view',
        'report_viewed',
        'clinical_combo_68_report_viewed',
        'sds_20_report_viewed',
    ];

    /**
     * @var list<string>
     */
    public const PDF_DOWNLOAD_ALIASES = [
        self::PDF_DOWNLOAD,
        'report_pdf_view',
    ];

    /**
     * @var list<string>
     */
    public const SHARE_CLICK_ALIASES = [
        self::SHARE_CLICK,
    ];

    public static function canonicalize(string $eventName): string
    {
        $normalized = strtolower(trim($eventName));

        return self::LEGACY_ALIAS_MAP[$normalized] ?? $normalized;
    }

    public static function isCanonical(string $eventName): bool
    {
        return in_array(strtolower(trim($eventName)), self::CANONICAL_EVENTS, true);
    }
}
