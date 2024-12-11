<?php

namespace Wm\WmPackage\Enums;

use Maatwebsite\Excel\Excel;

enum ExportFormat: string
{
    case XLSX = Excel::XLSX;
    case CSV = Excel::CSV;

    public function label(): string
    {
        return match ($this) {
            self::XLSX => 'Excel (XLSX)',
            self::CSV => 'CSV',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::XLSX => 'xlsx',
            self::CSV => 'csv',
        };
    }

    public static function toArray(): array
    {
        return array_reduce(self::cases(), function ($carry, ExportFormat $format) {
            $carry[$format->value] = $format->label();
            return $carry;
        }, []);
    }
}
