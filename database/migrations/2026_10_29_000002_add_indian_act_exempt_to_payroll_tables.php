<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            // Employment income exempt from tax under section 87 of the Indian
            // Act. No income tax is withheld and the T4 reports the income under
            // code 71 instead of box 14 (CRA RC4120, "Indians – Employment").
            // Distinct from income_tax_exempt, whose income is still taxable.
            $table->boolean('indian_act_exempt')->default(false)->after('income_tax_exempt');
        });

        Schema::table('pay_run_lines', function (Blueprint $table) {
            // The profile's Indian Act exemption, stamped when the line is
            // calculated, so a status change during the year splits the T4
            // between box 14 and code 71. Null on lines calculated before this
            // column existed: the T4 falls back to the profile's setting.
            $table->boolean('indian_act_exempt')->nullable()->after('province_of_employment');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table) {
            $table->dropColumn('indian_act_exempt');
        });

        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn('indian_act_exempt');
        });
    }
};
