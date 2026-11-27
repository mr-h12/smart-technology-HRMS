<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

/** What a proposed daily wage is for (`employee_wage_proposals.kind`): a new day laborer, or an existing one's new wage (#79). */
enum WageProposalKind: string
{
    case NewLaborer = 'new_laborer';
    case WageChange = 'wage_change';
}
