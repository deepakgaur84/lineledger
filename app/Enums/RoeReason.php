<?php

namespace App\Enums;

/**
 * Service Canada ROE "reason for issuing" codes (Block 16).
 */
enum RoeReason: string
{
    case ShortageOfWork = 'A';
    case Quit = 'E';
    case Illness = 'D';
    case Dismissal = 'M';
    case Retirement = 'G';
    case Leave = 'N';
    case Other = 'K';

    public function label(): string
    {
        return match ($this) {
            self::ShortageOfWork => __('A — Shortage of work / layoff'),
            self::Quit => __('E — Quit'),
            self::Illness => __('D — Illness or injury'),
            self::Dismissal => __('M — Dismissal'),
            self::Retirement => __('G — Retirement'),
            self::Leave => __('N — Leave of absence'),
            self::Other => __('K — Other'),
        };
    }

    /**
     * The three-character separation code ROE Web takes in Block 16: the reason
     * letter followed by "00", the code with no sub-reason (ROE Web user
     * requirements, Appendix D, table 16.1).
     */
    public function roeWebCode(): string
    {
        return $this->value.'00';
    }

    /**
     * Block 14 (expected date of recall) when no recall date is known: "N" (not
     * returning) after a quit, a retirement or a dismissal, which end the
     * employment and for which ROE Web refuses a recall date, and "U" (unknown)
     * otherwise.
     */
    public function expectedRecallCode(): string
    {
        return match ($this) {
            self::Quit, self::Retirement, self::Dismissal => 'N',
            default => 'U',
        };
    }
}
