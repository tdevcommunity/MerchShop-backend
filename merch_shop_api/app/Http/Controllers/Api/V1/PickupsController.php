<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
final class PickupsController extends ApiController {
    public function index(): JsonResponse { return $this->jsonResponse(['data'=>[]]); }
    public function validate(string $id): JsonResponse { return $this->jsonResponse(['data'=>['message'=>'Validated']]); }
}
