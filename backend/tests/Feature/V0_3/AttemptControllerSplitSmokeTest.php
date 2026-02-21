<?php

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Http\Controllers\API\V0_3\AttemptWriteController;
use App\Http\Controllers\API\V0_3\CommerceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AttemptControllerSplitSmokeTest extends TestCase
{
    public function test_attempt_routes_are_wired_to_split_controllers(): void
    {
        $routes = Route::getRoutes();

        $start = $routes->match(Request::create('/api/v0.3/attempts/start', 'POST'));
        $submit = $routes->match(Request::create('/api/v0.3/attempts/submit', 'POST'));
        $show = $routes->match(Request::create('/api/v0.3/attempts/00000000-0000-0000-0000-000000000000', 'GET'));
        $result = $routes->match(Request::create('/api/v0.3/attempts/00000000-0000-0000-0000-000000000000/result', 'GET'));
        $report = $routes->match(Request::create('/api/v0.3/attempts/00000000-0000-0000-0000-000000000000/report', 'GET'));
        $reportPdf = $routes->match(Request::create('/api/v0.3/attempts/00000000-0000-0000-0000-000000000000/report.pdf', 'GET'));
        $skus = $routes->match(Request::create('/api/v0.3/skus', 'GET'));

        $this->assertStringContainsString(AttemptWriteController::class . '@start', $start->getActionName());
        $this->assertStringContainsString(AttemptWriteController::class . '@submit', $submit->getActionName());
        $this->assertStringContainsString(AttemptReadController::class . '@show', $show->getActionName());
        $this->assertStringContainsString(AttemptReadController::class . '@result', $result->getActionName());
        $this->assertStringContainsString(AttemptReadController::class . '@report', $report->getActionName());
        $this->assertStringContainsString(AttemptReadController::class . '@reportPdf', $reportPdf->getActionName());
        $this->assertStringContainsString(CommerceController::class . '@listSkus', $skus->getActionName());

        $this->assertNotNull($routes->getByName('api.v0_3.attempts.show'));
        $this->assertNotNull($routes->getByName('api.v0_3.attempts.result'));
        $this->assertNotNull($routes->getByName('api.v0_3.attempts.report'));
        $this->assertNotNull($routes->getByName('api.v0_3.attempts.report_pdf'));
        $this->assertNotNull($routes->getByName('api.v0_3.skus'));
    }
}
