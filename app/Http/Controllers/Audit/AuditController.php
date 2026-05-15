<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditReportService;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private AuditReportService $auditReportService)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'actor_usuario_id' => ['nullable', 'integer'],
            'actor_persona_id' => ['nullable', 'integer'],
            'actor_rol_id' => ['nullable', 'integer'],
            'modulo' => ['nullable', 'string', 'max:80'],
            'tabla' => ['nullable', 'string', 'max:63'],
            'operacion' => ['nullable', 'string', 'size:1'],
            'request_id' => ['nullable', 'string', 'max:80'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($this->auditReportService->detail($filters));
    }

    public function summary(Request $request)
    {
        $filters = $request->validate([
            'actor_usuario_id' => ['nullable', 'integer'],
            'actor_persona_id' => ['nullable', 'integer'],
            'actor_rol_id' => ['nullable', 'integer'],
            'modulo' => ['nullable', 'string', 'max:80'],
            'tabla' => ['nullable', 'string', 'max:63'],
            'operacion' => ['nullable', 'string', 'size:1'],
            'request_id' => ['nullable', 'string', 'max:80'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
        ]);

        return response()->json($this->auditReportService->summary($filters));
    }
}
