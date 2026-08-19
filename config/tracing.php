<?php

return [
    'sla_hours' => (int) env('TRACING_SLA_HOURS', 24),
    'regulator_sla_hours' => (int) env('TRACING_REGULATOR_SLA_HOURS', 24),
    'supplier_sla_hours' => (int) env('TRACING_SUPPLIER_SLA_HOURS', 48),
];
