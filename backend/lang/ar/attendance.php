<?php

return [
    'refusal' => [
        'concurrency_conflict' => 'عدّل مستخدم آخر هذا السجل. حمّل آخر نسخة ثم أعد المحاولة.',
        'period_locked' => 'فترة الحضور مقفلة ولا يمكن تعديلها.',
        'future_date' => 'لا يمكن تسجيل حضور لتاريخ لم يأتِ بعد.',
        'employee_not_active' => 'الموظف غير مُعيَّن في هذا التاريخ.',
        'missing_days_unconfirmed' => 'توجد أيام غير مسجلة في الفترة؛ أكّد القفل للمتابعة.',
        'period_already_locked' => 'الفترة مقفلة بالفعل.',
        'period_not_locked' => 'الفترة غير مقفلة.',
        'leave_type_missing' => 'لا يوجد نوع إجازة نشط بالكود :code.',
        'template_mismatch' => 'الملف لا يطابق قالب الحضور لهذه الفترة.',
        'unreadable_file' => 'تعذّرت قراءة الملف.',
        'too_many_rows' => 'يحتوي الملف على أكثر من :max صف.',
    ],
    'rule' => [
        'field_not_allowed' => 'هذا الحقل لا ينطبق على هذا اليوم.',
        'out_of_bounds' => 'القيمة خارج الحدود المسموح بها.',
        'site_required' => 'اختر موقعًا أو نوع يوم.',
        'site_not_allowed' => 'لا يُسجَّل لموظفي الشركة موقع عمل.',
        'site_inactive' => 'هذا الموقع غير نشط.',
        'out_before_in' => 'وقت الانصراف قبل وقت الحضور.',
        'times_need_both' => 'أدخل وقتي الحضور والانصراف معًا.',
    ],
    'invalid_period' => 'بداية الفترة غير صحيحة لهذه المجموعة.',
    'template' => [
        'code' => 'الرقم الوظيفي',
        'name' => 'الاسم',
        'present' => 'حاضر',
    ],
    'leave_name' => [
        'friday' => 'إجازة الجمعة',
        'saturday' => 'إجازة السبت',
        'official_holiday' => 'إجازة رسمية',
        'sick' => 'إجازة مرضية',
        'rest' => 'راحة',
        'absent' => 'غياب',
        'other' => 'أخرى',
    ],
    'import' => [
        'unknown_employee' => 'الرقم الوظيفي غير موجود.',
        'unknown_site' => 'الموقع أو نوع اليوم غير موجود.',
        'duplicate_row' => 'هذا الموظف مكرر في الملف.',
    ],
];
