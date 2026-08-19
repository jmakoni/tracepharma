<?php

return [
    'max_batch_size' => (int) env('SSCC_MAX_BATCH_SIZE', 50),

    /*
     * Label template version stamped onto each generated SSCC label. Bump this whenever the
     * label layout/content changes so reprints are auditable against the template that
     * produced the original.
     */
    'label_template_version' => env('SSCC_LABEL_TEMPLATE_VERSION', 'v1'),

    /*
     * Default remaining-serial threshold used when tenant settings.sscc.low_water_mark is unset.
     */
    'default_low_water_mark' => (int) env('SSCC_DEFAULT_LOW_WATER_MARK', 5000),

    'require_number_range' => (bool) env('SSCC_REQUIRE_NUMBER_RANGE', false),

    /*
     * Cap for exhaustive serial scans in random allocation fallbacks. Larger spaces throw
     * instead of iterating toward the full GS1 serial domain under a pool lock.
     */
    'max_random_scan' => (int) env('SSCC_MAX_RANDOM_SCAN', 100000),

    /*
     * Sliding window size used when scanning for unused serials during sequential pool
     * allocation/preview. Bounds each "used serial" lookup instead of querying an unbounded
     * [floor, maxSerial] range in one pass.
     */
    'max_sequential_scan' => (int) env('SSCC_MAX_SEQUENTIAL_SCAN', 100000),

    /*
     * Upper bound for a single managed number range size (allocation steps).
     */
    'max_range_size' => (int) env('SSCC_MAX_RANGE_SIZE', 1000000),
];
