<?php

namespace Icloudpd\Progress;

class Progress
{
    private int $_photos_count = 0;
    private int $_photos_counter = 0;
    public int $photos_percent = 0;
    public string $photos_last_message = '';
    public string $waiting_readable = '';
    public bool $resume = false;
    public bool $cancel = false;
    private int $_waiting = 0;

    // the @property / @x.setter pairs of upstream are property hooks
    public int $waiting {
        get => $this->_waiting;
        set {
            $this->_waiting = $value;
            $this->waiting_readable = self::timedelta_str($value);
        }
    }

    public int $photos_count {
        get => $this->_photos_count;
        set {
            $this->_photos_count = $value;
            $this->update_photos_percent();
        }
    }

    public int $photos_counter {
        get => $this->_photos_counter;
        set {
            $this->_photos_counter = $value;
            $this->update_photos_percent();
        }
    }

    public function reset(): void
    {
        $this->_photos_count = 0;
        $this->_photos_counter = 0;
        $this->photos_percent = 0;
        $this->_waiting = 0;
        $this->waiting_readable = '';
        $this->resume = false;
        $this->cancel = false;
    }

    // the body shared by both setters upstream
    private function update_photos_percent(): void
    {
        if ($this->photos_count != 0) {
            // Python's round() is half-to-even
            $this->photos_percent = (int) round(100 / $this->photos_count * $this->photos_counter, 0, PHP_ROUND_HALF_EVEN);
        } else {
            $this->photos_percent = 0;
        }
    }

    /** `str(datetime.timedelta(seconds=seconds))`: `[D day[s], ]H:MM:SS` */
    private static function timedelta_str(int $seconds): string
    {
        $days = intdiv($seconds - ($seconds % 86400 + 86400) % 86400, 86400);
        $rest = $seconds - $days * 86400;
        $s = sprintf('%d:%02d:%02d', intdiv($rest, 3600), intdiv($rest % 3600, 60), $rest % 60);
        if ($days) {
            $s = sprintf('%d day%s, ', $days, abs($days) != 1 ? 's' : '') . $s;
        }
        return $s;
    }
}
