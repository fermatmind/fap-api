<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\OpsI18n\OpsUiText;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LocalizeOpsUiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldLocalize($request)) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $response->setData($this->localizeJson($response->getData(true)));

            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('content-type', ''));
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }

        $response->setContent(OpsUiText::localizeHtml($content));

        return $response;
    }

    private function shouldLocalize(Request $request): bool
    {
        if (app()->getLocale() !== 'zh_CN') {
            return false;
        }

        if (! $this->isOpsRequest($request)) {
            return false;
        }

        return $this->hasExplicitOpsLocale($request);
    }

    private function isOpsRequest(Request $request): bool
    {
        if ($request->is('ops') || $request->is('ops/*')) {
            return true;
        }

        $referer = (string) $request->headers->get('referer', '');

        return $referer !== '' && str_contains($referer, '/ops');
    }

    private function hasExplicitOpsLocale(Request $request): bool
    {
        if ($request->attributes->has('ops_locale_explicit')) {
            return (bool) $request->attributes->get('ops_locale_explicit');
        }

        $queryLocale = (string) ($request->query('locale') ?: $request->query('lang') ?: '');
        if ($queryLocale !== '') {
            return true;
        }

        return (bool) session(SetOpsLocale::EXPLICIT_SESSION_KEY, false);
    }

    private function localizeJson(mixed $value): mixed
    {
        if (is_string($value)) {
            return OpsUiText::localizeHtml($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->localizeJson($item);
        }

        return $value;
    }
}
