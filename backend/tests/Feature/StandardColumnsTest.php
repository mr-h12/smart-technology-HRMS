<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StandardColumnsTest extends TestCase
{
    public function test_macros_create_the_fixed_precisions(): void
    {
        Schema::dropIfExists('macro_probe');
        Schema::create('macro_probe', function (Blueprint $t): void {
            $t->standardColumns();
            $t->money('amount');
            $t->percentage('rate');
            $t->quantity('qty');
        });

        $cols = DB::table('information_schema.columns')
            ->where('table_name', 'macro_probe')
            ->get(['column_name', 'data_type', 'numeric_precision', 'numeric_scale'])
            ->keyBy('column_name');
        Schema::drop('macro_probe');

        $this->assertSame('numeric', $cols['amount']->data_type);
        $this->assertSame([18, 6], [$cols['amount']->numeric_precision, $cols['amount']->numeric_scale]);
        $this->assertSame([6, 3], [$cols['rate']->numeric_precision, $cols['rate']->numeric_scale]);
        $this->assertSame([14, 4], [$cols['qty']->numeric_precision, $cols['qty']->numeric_scale]);
        $this->assertSame('uuid', $cols['id']->data_type);
        $this->assertArrayHasKey('deleted_at', $cols->all());
    }
}
