<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Details the CRA's T4 / T4A XML needs that payroll did not record:
     *
     *  - the employer-offered dental benefits code, T4 box 45 (required on every
     *    slip from the 2023 tax year, values 1–5), set for the organization and
     *    optionally overridden per employee;
     *  - the CRA representative ID (RepID) for an organization whose
     *    representative uploads the file through Represent a Client.
     *
     * All nullable: nothing to backfill. Filing the XML is refused until box 45
     * is chosen, so no slip is filed with a guessed value.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('payroll_dental_benefits_code')->nullable()->after('payroll_rp_account');
            $table->string('payroll_cra_rep_id', 7)->nullable()->after('payroll_dental_benefits_code');
        });

        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('dental_benefits_code')->nullable()->after('qpip_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn('dental_benefits_code');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['payroll_dental_benefits_code', 'payroll_cra_rep_id']);
        });
    }
};
