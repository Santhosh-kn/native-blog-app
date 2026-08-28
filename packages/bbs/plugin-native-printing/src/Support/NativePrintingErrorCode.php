<?php

declare(strict_types=1);

namespace Bbs\NativePrinting\Support;

final class NativePrintingErrorCode
{
    public const PRINTING_UNAVAILABLE = 'PRINTING_UNAVAILABLE';

    public const FILE_NOT_FOUND = 'FILE_NOT_FOUND';

    public const FILE_NOT_READABLE = 'FILE_NOT_READABLE';

    public const FILE_OUTSIDE_APP_STORAGE =
        'FILE_OUTSIDE_APP_STORAGE';

    public const INVALID_FILE_TYPE = 'INVALID_FILE_TYPE';

    public const INVALID_PDF = 'INVALID_PDF';

    public const INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';

    public const PREVIEW_UNAVAILABLE = 'PREVIEW_UNAVAILABLE';

    public const PREVIEW_FAILED = 'PREVIEW_FAILED';

    public const PRINT_DIALOG_FAILED = 'PRINT_DIALOG_FAILED';

    public const PRINT_CANCELLED = 'PRINT_CANCELLED';

    public const PRINT_JOB_FAILED = 'PRINT_JOB_FAILED';

    public const ACTIVITY_UNAVAILABLE = 'ACTIVITY_UNAVAILABLE';

    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    private function __construct()
    {
    }
}
