<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employer's Revenu Québec source-deductions account, set in Settings →
 * Payroll: the 10-digit identification number and the file number (RS plus four
 * digits), as printed on the Remittance of Source Deductions and Employer
 * Contributions and the RL-1 summary (RLZ-1.S). RL-1 slips are filed under them;
 * the GST/HST number in `tax_number` is a different account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('payroll_rq_identification_number', 10)->nullable()->after('payroll_rp_account');
            $table->string('payroll_rq_file_number', 6)->nullable()->after('payroll_rq_identification_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['payroll_rq_identification_number', 'payroll_rq_file_number']);
        });
    }
};
