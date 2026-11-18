<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain;

/** The HR forms served as PDFs; the value is the `form_type` column of `hr_form_exports`. */
enum PdfType: string
{
    case ExperienceCertificate = 'experience_certificate';
    case LeaveRequest = 'leave_request';
    case AdvanceRequest = 'advance_request';
    case OvertimeRequest = 'overtime_request';

    /** Null when the type is never signed. */
    public function approvePermission(): ?string
    {
        return match ($this) {
            self::ExperienceCertificate => 'hr_forms.approve',
            self::LeaveRequest => 'hr_forms.approve_leave_request',
            self::AdvanceRequest, self::OvertimeRequest => null,
        };
    }

    /** Whether a caller holding the route's hr_forms permission at own scope only may use the type (on employees in reach). */
    public function ownScope(): bool
    {
        return $this === self::AdvanceRequest;
    }

    /** The layout new rows of this type are made with. A row renders, is signed and is retried only in its own version. */
    public function templateVersion(): int
    {
        return match ($this) {
            self::LeaveRequest, self::ExperienceCertificate => 2,
            self::AdvanceRequest, self::OvertimeRequest => 1,
        };
    }

    public function fileName(int $serial, string $stage): string
    {
        return str_replace('_', '-', $this->value)."-{$serial}-{$stage}.pdf";
    }
}
