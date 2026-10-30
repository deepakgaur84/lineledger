<?php

namespace App\Exceptions\Payroll;

use RuntimeException;

/**
 * Thrown instead of writing an RL-1 XML file without the employer's Revenu
 * Québec identification and file numbers (Settings → Payroll): RL-1 slips are
 * filed under that account, and no other number may stand in for it. The RL-1
 * page shows the message rather than downloading the file.
 */
class Rl1ExportBlockedException extends RuntimeException {}
