<?php

declare(strict_types=1);

return [
    'wechat' => [
        'default' => [
            'mch_id' => env('WECHAT_PAY_MCH_ID', ''),
            'mch_secret_key' => env('WECHAT_PAY_MCH_SECRET_KEY', ''),
            'mch_secret_cert' => env('WECHAT_PAY_MCH_SECRET_CERT', storage_path('app/cert/wechat/apiclient_key.pem')),
            'mch_public_cert_path' => env('WECHAT_PAY_MCH_PUBLIC_CERT', storage_path('app/cert/wechat/apiclient_cert.pem')),
            'wechat_public_cert_path' => [
                env('WECHAT_PAY_PLATFORM_PUB_KEY_ID', '') => env(
                    'WECHAT_PAY_PLATFORM_CERT',
                    storage_path('app/cert/wechat/wechatpay_platform_cert.pem')
                ),
            ],
            'app_id' => env('WECHAT_PAY_APP_ID', ''),
            'mp_app_id' => env('WECHAT_PAY_MP_APP_ID', ''),
            'mini_app_id' => env('WECHAT_PAY_MINI_APP_ID', ''),
            'notify_url' => env('WECHAT_PAY_NOTIFY_URL', ''),
            'return_url' => env('WECHAT_PAY_RETURN_URL', ''),
            'mode' => (int) env('WECHAT_PAY_MODE', 0),
            'http' => [
                'base_uri' => env('WECHAT_PAY_BASE_URI', 'https://api.mch.weixin.qq.com'),
                'timeout' => (float) env('WECHAT_PAY_HTTP_TIMEOUT', 5.0),
                'connect_timeout' => (float) env('WECHAT_PAY_HTTP_CONNECT_TIMEOUT', 5.0),
            ],
        ],
    ],
    'alipay' => [
        'default' => [
            'app_id' => env('ALIPAY_APP_ID', ''),
            'merchant_private_key' => env('ALIPAY_MERCHANT_PRIVATE_KEY', ''),
            'merchant_private_key_path' => env(
                'ALIPAY_MERCHANT_PRIVATE_KEY_PATH',
                storage_path('app/cert/alipay/app_private_key.pem')
            ),
            'app_public_cert_path' => env(
                'ALIPAY_APP_PUBLIC_CERT',
                storage_path('app/cert/alipay/appCertPublicKey.crt')
            ),
            'alipay_public_cert_path' => env(
                'ALIPAY_PUBLIC_CERT',
                storage_path('app/cert/alipay/alipayCertPublicKey_RSA2.crt')
            ),
            'alipay_root_cert_path' => env(
                'ALIPAY_ROOT_CERT',
                storage_path('app/cert/alipay/alipayRootCert.crt')
            ),
            'notify_url' => env('ALIPAY_NOTIFY_URL', ''),
            'return_url' => env('ALIPAY_RETURN_URL', ''),
            'mode' => env('ALIPAY_MODE', 'normal'),
        ],
    ],
];
