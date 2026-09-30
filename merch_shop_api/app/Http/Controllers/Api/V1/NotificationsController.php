<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
final class NotificationsController extends ApiController {
    public function index(): JsonResponse { return $this->jsonResponse(['data'=>[]]); }
    public function markRead(): JsonResponse { return $this->jsonResponse(['data'=>['message'=>'Lu']]); }
}
