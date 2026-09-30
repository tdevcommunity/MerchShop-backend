<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
final class InventoryController extends ApiController {
    public function index(): JsonResponse { return $this->jsonResponse(['data'=>[]]); }
    public function update(string $uuid): JsonResponse { return $this->jsonResponse(['data'=>['message'=>'Updated']]); }
}
