<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

enum DocumentKind: string
{
    case NationalIdCopy = 'national_id_copy';
    case WorkStub = 'work_stub';
    case CriminalRecord = 'criminal_record';
    case Qualification = 'qualification';
    case BirthCertificate = 'birth_certificate';
    case InsuranceForm6 = 'insurance_form_6';
    case Photos = 'photos';
    case MilitaryStatus = 'military_status';
    case Other = 'other';
}
