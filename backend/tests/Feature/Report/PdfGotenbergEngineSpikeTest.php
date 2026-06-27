<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Report\Pdf\GotenbergChromiumPdfClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PdfGotenbergEngineSpikeTest extends TestCase
{
    public function test_chromium_html_conversion_returns_pdf_without_changing_public_report_route(): void
    {
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');

        Http::fake([
            'gotenberg:3000/forms/chromium/convert/html' => Http::response('%PDF-1.4 gotenberg spike', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $pdf = app(GotenbergChromiumPdfClient::class)->convertHtml($this->resultPrintHtml());

        $this->assertStringStartsWith('%PDF-', $pdf);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://gotenberg:3000/forms/chromium/convert/html'
                && $request->method() === 'POST'
                && str_contains($request->body(), 'Personality Traits')
                && str_contains($request->body(), 'Your Career Path')
                && str_contains($request->body(), 'Your Personal Growth')
                && str_contains($request->body(), 'Your Relationships')
                && str_contains($request->body(), 'Noto Sans SC');
        });

        $route = Route::getRoutes()->match(Request::create('/api/v0.3/attempts/00000000-0000-0000-0000-000000000000/report.pdf', 'GET'));

        $this->assertStringContainsString('AttemptReadController@reportPdf', $route->getActionName());
    }

    public function test_spike_command_emits_attachment_ready_pdf_evidence(): void
    {
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://127.0.0.1:3000');

        Http::fake([
            '127.0.0.1:3000/forms/chromium/convert/html' => Http::response('%PDF-1.4 gotenberg command spike', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $htmlFile = tempnam(sys_get_temp_dir(), 'fm-gotenberg-spike-').'.html';
        $output = tempnam(sys_get_temp_dir(), 'fm-gotenberg-spike-').'.pdf';
        file_put_contents($htmlFile, $this->resultPrintHtml());

        $exitCode = Artisan::call('pdf:gotenberg-spike', [
            '--html-file' => $htmlFile,
            '--output' => $output,
            '--json' => true,
        ]);

        $commandOutput = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ok": true', $commandOutput);
        $this->assertStringContainsString('gotenberg_chromium', $commandOutput);
        $this->assertStringContainsString('api_route_changed', $commandOutput);
        $this->assertStringContainsString('public_exposure_allowed', $commandOutput);

        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($output));
    }

    public function test_public_gotenberg_or_print_urls_are_rejected(): void
    {
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'https://pdf.fermatmind.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gotenberg base URL must use http on a private network.');

        app(GotenbergChromiumPdfClient::class)->convertHtml($this->resultPrintHtml());
    }

    public function test_public_print_url_is_rejected_even_with_private_gotenberg(): void
    {
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://10.0.0.12:3000');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('print URL must resolve to a private/internal host.');

        app(GotenbergChromiumPdfClient::class)->convertUrl('http://fermatmind.com/zh/result/example/print');
    }

    private function resultPrintHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: "Noto Sans SC", "Noto Sans CJK SC", sans-serif; }
    section { break-inside: avoid; page-break-inside: avoid; }
  </style>
</head>
<body>
  <main data-result-print-root="true">
    <section><h1>MBTI 完整人格报告</h1><p>中文字体正常。</p></section>
    <section><h2>Personality Traits</h2><p>Trait cards render in print mode.</p></section>
    <section><h2>Your Career Path</h2><p>Career cards render in print mode.</p></section>
    <section><h2>Your Personal Growth</h2><p>Growth cards render in print mode.</p></section>
    <section><h2>Your Relationships</h2><p>Relationship cards render in print mode.</p></section>
  </main>
</body>
</html>
HTML;
    }
}
