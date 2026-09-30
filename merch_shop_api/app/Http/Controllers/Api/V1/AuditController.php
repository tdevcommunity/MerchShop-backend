<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
final class AuditController extends ApiController {
    public function index(): JsonResponse { return $this->jsonResponse(['data'=>[]]); }
}
