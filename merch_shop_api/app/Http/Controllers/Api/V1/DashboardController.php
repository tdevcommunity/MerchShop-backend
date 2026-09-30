<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;

final class DashboardController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->jsonResponse([
            'data' => [
                'revenue' => 0,
                'orders' => 0,
                'paid_orders' => 0,
            ]
        ]);
    }
}
