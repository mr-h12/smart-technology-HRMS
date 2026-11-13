<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/** Why an attendance write was refused; the value is the API error code and the lang key suffix. */
enum AttendanceRefusal: string
{
    case PeriodSigned = 'period_signed';
    case SignoffNotDue = 'signoff_not_due';
    case PeriodLocked = 'period_locked';
    case FutureDate = 'future_date';
    case EmployeeNotActive = 'employee_not_active';
    case MissingDaysUnconfirmed = 'missing_days_unconfirmed';
    case PeriodAlreadyLocked = 'period_already_locked';
    case PeriodNotLocked = 'period_not_locked';
    case LeaveTypeMissing = 'leave_type_missing';
    case TemplateMismatch = 'template_mismatch';
    case UnreadableFile = 'unreadable_file';
    case TooManyRows = 'too_many_rows';
    case SiteNotInReach = 'site_not_in_reach';
    case LeaveNeedsRequest = 'leave_needs_request';
    case LeaveDaysBlocked = 'leave_days_blocked';
    case OutsideManagerWindow = 'outside_manager_window';
    case HrDay = 'hr_day';
    case OpenEarlierDay = 'open_earlier_day';
    case OpenDays = 'open_days';
    // the site manager's day (PR #22 review): stable codes for each business refusal, never a field error
    case ClearNotAllowed = 'clear_not_allowed';
    case SiteNotForManager = 'site_not_for_manager';
    case ArrivalRequired = 'arrival_required';
    case DepartureOnly = 'departure_only';
    case CrewOnOtherSite = 'crew_on_other_site';
    case CrewHasDay = 'crew_has_day';
    case CrewMovedHasDay = 'crew_moved_has_day';
    // the site workbook's confirm (W15): the stored days changed since the preview
    case PreviewStale = 'preview_stale';

    public function status(): int
    {
        return match ($this) {
            self::PeriodAlreadyLocked, self::LeaveDaysBlocked, self::PreviewStale => 409,
            self::PeriodLocked, self::PeriodSigned => 423,
            self::FutureDate, self::SignoffNotDue, self::EmployeeNotActive, self::MissingDaysUnconfirmed, self::PeriodNotLocked, self::LeaveTypeMissing,
            self::TemplateMismatch, self::UnreadableFile, self::TooManyRows, self::SiteNotInReach, self::LeaveNeedsRequest,
            self::OutsideManagerWindow, self::HrDay, self::OpenEarlierDay, self::OpenDays, self::ClearNotAllowed, self::SiteNotForManager,
            self::ArrivalRequired, self::DepartureOnly, self::CrewOnOtherSite, self::CrewHasDay, self::CrewMovedHasDay => 422,
        };
    }
}
