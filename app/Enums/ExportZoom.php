<?php

namespace App\Enums;

/**
 * Column granularity of the exported Gantt, mirroring the timeline's own zoom
 * buttons so a download matches what the page was showing.
 */
enum ExportZoom: string
{
    case Week = 'week';

    case Month = 'month';

    case Quarter = 'quarter';
}
