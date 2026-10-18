<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('hr');
    }

    public function test_validation_messages_follow_accept_language(): void
    {
        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/employees', ['gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1'])
            ->assertStatus(422)->assertJsonPath('error.details.0.message', 'حقل الاسم بالعربية مطلوب.')
            ->assertHeader('Content-Language', 'ar');
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->postJson('/api/v1/employees', ['gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1'])
            ->assertJsonPath('error.details.0.message', 'The Arabic name field is required.')
            ->assertJsonPath('error.message', 'The given data was invalid.');
    }

    public function test_unsupported_or_missing_language_falls_back_to_arabic(): void
    {
        $this->withHeader('Accept-Language', 'fr')->getJson('/api/v1/employees/'.Str::uuid7())->assertNotFound()->assertHeader('Content-Language', 'ar');
        $this->getJson('/api/v1/employees/'.Str::uuid7())->assertJsonPath('error.message', 'المورد غير موجود.');
    }

    public function test_service_rule_messages_are_translated(): void
    {
        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/employees', ['name_ar' => 'أ', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1', 'pay_method' => 'bank'])
            ->assertJsonPath('error.details.0.message', 'رقم الحساب البنكي مطلوب عند صرف المرتب بتحويل بنكي.');
    }

    public function test_identity_refusals_follow_accept_language(): void
    {
        $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/login', ['email' => 'x@smart.test', 'password' => 'x'])
            ->assertStatus(401)->assertJsonPath('error.message', 'These credentials do not match our records.');
    }
}
