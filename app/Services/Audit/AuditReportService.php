<?php

namespace App\Services\Audit;

use App\Queries\Audit\AuditQuery;

class AuditReportService
{
    public function __construct(private AuditQuery $auditQuery)
    {
    }

    public function detail(array $filters = []): array
    {
        return $this->auditQuery->search($filters);
    }

    public function summary(array $filters = []): array
    {
        return $this->auditQuery->summary($filters);
    }
}

